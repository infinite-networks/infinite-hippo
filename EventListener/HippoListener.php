<?php declare(strict_types=1);

/**
 * This file is part of the InfiniteHippoBundle project.
 *
 * (c) Infinite Networks Pty Ltd <http://www.infinite.net.au>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Infinite\HippoBundle\EventListener;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

class HippoListener implements EventSubscriberInterface
{
    private $cacheDir;
    private $errorRoles;
    private $logsDir;
    private $performanceLoggingThreshold;
    private $projectDir;
    private $slackWebhookUrl;
    private $slackRateLimit;
    private $tokenStorage;

    public function __construct(
        string $cacheDir,
        array $errorRoles,
        string $logsDir,
        ?float $performanceLoggingThreshold,
        string $projectDir,
        ?string $slackWebhookUrl,
        ?int $slackRateLimit,
        TokenStorageInterface $tokenStorage
    )
    {
        $this->cacheDir = $cacheDir;
        $this->errorRoles = $errorRoles;
        $this->logsDir = $logsDir;
        $this->performanceLoggingThreshold = $performanceLoggingThreshold;
        $this->projectDir = $projectDir;
        $this->slackWebhookUrl = $slackWebhookUrl;
        $this->slackRateLimit = $slackRateLimit;
        $this->tokenStorage = $tokenStorage;
    }

    public function onException(ExceptionEvent $event)
    {
        if ($event->getThrowable() instanceof HttpException) {
            return;
        }

        try {
            $event->getRequest()->attributes->set('_infinite_hippo_exception', $event->getThrowable());

            if (!($token = $this->tokenStorage->getToken())) {
                return;
            }

            if (method_exists($token, 'getRoleNames')) {
                $currentUserRoles = $token->getRoleNames();
            } else {
                $currentUserRoles = array_map(function ($role) { return $role->getRole(); }, $token->getRoles());
            }

            if (method_exists($token, 'getUserIdentifier')) {
                $username = $token->getUserIdentifier();
            } else {
                $username = $token->getUsername();
            }

            if (array_intersect($currentUserRoles, $this->errorRoles)) {
                $this->enqueueSlackMessage($this->formatErrorSlackMessage($event->getThrowable(), $username, $event->getRequest()), $event->getRequest());
            }
        } catch (\Throwable $t) {
            // We're in an exception listener, so don't leak any new exceptions.
        }
    }

    public function onRequest(RequestEvent $event)
    {
        $event->getRequest()->attributes->set('_infinite_hippo_start_time', microtime(true));
    }

    public function onResponse(ResponseEvent $event): void
    {
        $startTime = $event->getRequest()->attributes->get('_infinite_hippo_start_time');
        $endTime = microtime(true);
        $seconds = round($endTime - $startTime, 2);
        $memory = ceil(memory_get_peak_usage() / 1048576);
        $hungriness = $memory * $seconds;

        $vars = [
            'method' => $event->getRequest()->getMethod(),
            'url' => $event->getRequest()->getUri(),
            'route' => $event->getRequest()->attributes->get('_route'),
            'seconds' => $seconds,
            'megabytes' => $memory,
        ];

        if ($this->performanceLoggingThreshold === null || $hungriness >= $this->performanceLoggingThreshold) {
            $this->logToFile($vars);
            $this->enqueueSlackMessage($this->formatHungrySlackMessage($vars), $event->getRequest());
        }
    }

    public function onTerminate(TerminateEvent $event): void
    {
        $oldLogFilename = sprintf('performance-%s.log', date('Y-m-d', strtotime('8 days ago')));

        if (file_exists($this->logsDir . '/' . $oldLogFilename)) {
            @unlink($this->logsDir . '/' . $oldLogFilename);
        }

        foreach ($event->getRequest()->attributes->get('_infinite_hippo_slack_queue', []) as $message) {
            $this->logToSlack($message);
        }
    }

    private function enqueueSlackMessage($message, Request $request)
    {
        $queue = $request->attributes->get('_infinite_hippo_slack_queue', []);
        $queue[] = $message;
        $request->attributes->set('_infinite_hippo_slack_queue', $queue);
    }

    private function logToFile($vars): void
    {
        $logLine = sprintf('[%s] %s',
            date('c'),
            json_encode($vars)
        );

        $logFilename = sprintf('performance-%s.log', date('Y-m-d'));
        $fh = fopen($this->logsDir . '/' . $logFilename, 'a');

        if (!$fh) {
            throw new \RuntimeException('Cannot write to ' . $logFilename);
        }

        fwrite($fh, $logLine . "\n");
        fclose($fh);
    }

    private function logToSlack($slackMessage): void
    {
        if (!$this->slackWebhookUrl) {
            return;
        }

        // This function has some rate limiting built in, using a file named hippo-metadata.txt.
        // The first line stores the number of notifications sent in the last hour, and when that last hour started.
        // The second line does the same thing but for a five-minute block.

        // Steps:
        // 1: Lock the metadata file and parse the contents it.
        // 2: Return if we've hit the hourly notification limit, or half the limit for the last 5 minutes.
        // 3: Reset the hourly timestamp or five-minute timestamp if they're older than 1 hour or 5 minutes ago.
        // 4: Update the counts and write them back to the file.
        // 5: Unlock the metadata file.
        // 6: Notify Slack.
        $metadataFilename = $this->cacheDir . '/hippo-metadata.txt';
        $fh = fopen($metadataFilename, 'c+');

        try {
            flock($fh, LOCK_EX);

            // Parse the contents of the metadata file.
            // If the file is empty or doesn't parse, default to counts of zero starting from right now.
            $metadata = stream_get_contents($fh);
            if (preg_match('/^1 hour (\S+) (\d+)\n5 minute (\S+) (\d+)\s*$/', $metadata, $matches)) {
                $hourly          = new \DateTime($matches[1]);
                $hourlyCount     = (int)$matches[2];
                $fiveMinute      = new \DateTime($matches[3]);
                $fiveMinuteCount = (int)$matches[4];
            } else {
                $hourly = $fiveMinute = new \DateTime;
                $hourlyCount = $fiveMinuteCount = 0;
            }

            // Reset the timestamps if they're too old, and return early if we've reached the limits
            if ($hourly < (new \DateTime('1 hour ago'))) {
                $hourly = new \DateTime;
                $hourlyCount = 0;
            } elseif ($hourlyCount >= $this->slackRateLimit) {
                return;
            }

            if ($fiveMinute < (new \DateTime('5 minutes ago'))) {
                $fiveMinute = new \DateTime;
                $fiveMinuteCount = 0;
            } elseif ($fiveMinuteCount >= ceil($this->slackRateLimit / 2)) {
                return;
            }

            $hourlyCount++;
            $fiveMinuteCount++;

            fseek($fh, 0);
            fprintf($fh, "1 hour %s %d\n5 minute %s %d",
                $hourly->format('c'),
                $hourlyCount,
                $fiveMinute->format('c'),
                $fiveMinuteCount
            );
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }

        // Everything is in order. Notify Slack.
        $ch = curl_init($this->slackWebhookUrl);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($slackMessage));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_exec($ch);
        if (curl_getinfo($ch, CURLINFO_RESPONSE_CODE) < 200 || curl_getinfo($ch, CURLINFO_RESPONSE_CODE) > 299) {
            throw new \RuntimeException('Slack webhook failed');
        }
    }

    private function formatErrorSlackMessage(\Throwable $error, string $username, Request $request)
    {
        $trimDocRoot = function ($string) {
            if (substr($string, 0, strlen($this->projectDir)) === $this->projectDir) {
                $string = ltrim(substr($string, strlen($this->projectDir)), '/');
            }
            return $string;
        };

        $cause = null;

        foreach ($error->getTrace() as $frame) {
            if (false === strpos($frame['file'], '/vendor/') && false === strpos($frame['file'], '/var/cache/')) {
                $cause = $trimDocRoot($frame['file']) . ':' . $frame['line'];
                break;
            }
        }

        return [
            'text' => 'Production error detected',
            'blocks' => [
                [
                    'type' => 'section',
                    'fields' => [
                        [
                            'type' => 'mrkdwn',
                            'text' => '*ERROR*',
                        ],
                        [
                            'type' => 'mrkdwn',
                            'text' => '*USER*',
                        ],
                        [
                            'type' => 'plain_text',
                            'text' => '500',
                        ],
                        [
                            'type' => 'plain_text',
                            'text' => $username,
                        ],
                        [
                            'type' => 'mrkdwn',
                            'text' => '*METHOD*',
                        ],
                        [
                            'type' => 'mrkdwn',
                            'text' => '*URL*',
                        ],
                        [
                            'type' => 'plain_text',
                            'text' => $request->getMethod(),
                        ],
                        [
                            'type' => 'plain_text',
                            'text' => $request->getUri(),
                        ],
                    ],
                ],
                [
                    'type' => 'section',
                    'fields' => [
                        [
                            'type' => 'mrkdwn',
                            'text' => '*ERROR*',
                        ],
                        [
                            'type' => 'mrkdwn',
                            'text' => '*LOCATION*',
                        ],
                        [
                            'type' => 'plain_text',
                            'text' => $error->getMessage(),
                        ],
                        [
                            'type' => 'plain_text',
                            'text' => $trimDocRoot($error->getFile()) . ':' . $error->getLine(),
                        ],
                        [
                            'type' => 'mrkdwn',
                            'text' => '*CODE*',
                        ],
                        [
                            'type' => 'mrkdwn',
                            'text' => '*CAUSE*',
                        ],
                        [
                            'type' => 'plain_text',
                            'text' => (string)$error->getCode(),
                        ],
                        [
                            'type' => 'plain_text',
                            'text' => $cause ?? '(no additional information)',
                        ],
                    ],
                ],
            ],
        ];
    }

    private function formatHungrySlackMessage($vars): array
    {
        return [
            'text' => 'Hungry request detected',
            'blocks' => [
                [
                    'type' => 'section',
                    'fields' => [
                        [
                            'type' => 'mrkdwn',
                            'text' => '*URL*',
                        ],
                        [
                            'type' => 'mrkdwn',
                            'text' => '*MEMORY*',
                        ],
                        [
                            'type' => 'plain_text',
                            'text' => $vars['method'] . ' ' . $vars['url'],
                        ],
                        [
                            'type' => 'plain_text',
                            'text' => number_format($vars['megabytes']) . 'MB',
                        ],
                        [
                            'type' => 'mrkdwn',
                            'text' => '*Route*',
                        ],
                        [
                            'type' => 'mrkdwn',
                            'text' => '*TIME*',
                        ],
                        [
                            'type' => 'plain_text',
                            'text' => $vars['route'],
                        ],
                        [
                            'type' => 'plain_text',
                            'text' => number_format($vars['seconds']) . 's',
                        ],
                    ],
                ],
            ],
        ];
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ExceptionEvent::class => 'onException',
            RequestEvent::class => ['onRequest', 99999],
            ResponseEvent::class => ['onResponse', -99999],
            TerminateEvent::class => 'onTerminate',
        ];
    }
}
