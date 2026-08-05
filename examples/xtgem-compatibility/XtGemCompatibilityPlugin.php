<?php

declare(strict_types=1);

namespace XtScript\Examples\XtGemCompatibility;

use XtScript\Contract\PluginInterface;
use XtScript\Plugin\FunctionContext;
use XtScript\Plugin\PluginTrait;
use XtScript\Plugin\XtTagDefinition;

/**
 * Example only: historical XtGem-style <xt:...> compatibility layer.
 *
 * None of these tags are built into XtScript Engine. Registering this plugin is
 * an explicit application choice.
 */
final class XtGemCompatibilityPlugin implements PluginInterface
{
    use PluginTrait;

    /**
     * Names exposed by the surviving XtGem tutorial/building-tool index plus
     * xt:auth from the tutorial index. Wildcard dispatch below also accepts
     * older/private/third-party XT names not present in this list.
     *
     * @var list<string>
     */
    public const KNOWN_XT_TAGS = [
        'ad',
        'auth',
        'blog',
        'browser',
        'call',
        'chatroom',
        'community_members_count',
        'countdown',
        'counter',
        'country',
        'facebook_like',
        'filecount',
        'filelist',
        'forum',
        'gentime',
        'get_device_template',
        'google_plus',
        'guestbook',
        'guestbook_count',
        'include',
        'ip_address',
        'last_modified',
        'online',
        'poll',
        'random',
        'referer',
        'rss_reader',
        'site_search',
        'subscribers_count',
        'time_and_date',
        'twitter_follow',
        'url',
        'widget',
    ];

    public function __construct(
        private readonly XtGemAdapterInterface $adapter,
        private readonly bool $acceptUnknownXtTags = true,
    ) {
    }

    public function getName(): string
    {
        return 'example.xtgem-compatibility';
    }

    public function getXtTags(): iterable
    {
        foreach (self::KNOWN_XT_TAGS as $name) {
            yield new XtTagDefinition($name, $this->dispatch(...));
        }

        if ($this->acceptUnknownXtTags) {
            yield new XtTagDefinition('*', $this->dispatch(...));
        }
    }

    /** @param array<string, string> $attributes */
    private function dispatch(
        FunctionContext $context,
        string $name,
        array $attributes,
        ?string $body,
        string $raw,
    ): mixed {
        return $this->adapter->render($name, $attributes, $body, $raw, $context);
    }
}
