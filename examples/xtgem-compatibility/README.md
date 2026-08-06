# Optional `<xt:...>` compatibility plugin example

This directory demonstrates how an application can support historical XtGem-style
`<xt:...>` calls **without adding any XtGem function to XtScript Next for PHP core**.

The engine only provides the generic `XtTagDefinition` plugin hook. Without a
registered XT-tag plugin, `<xt:...>` remains literal output.

`XtGemCompatibilityPlugin::KNOWN_XT_TAGS` covers the names still listed by the
surviving XtGem tutorial/building-tool index:

`ad`, `auth`, `blog`, `browser`, `call`, `chatroom`,
`community_members_count`, `countdown`, `counter`, `country`, `facebook_like`,
`filecount`, `filelist`, `forum`, `gentime`, `get_device_template`,
`google_plus`, `guestbook`, `guestbook_count`, `include`, `ip_address`,
`last_modified`, `online`, `poll`, `random`, `referer`, `rss_reader`,
`site_search`, `subscribers_count`, `time_and_date`, `twitter_follow`, `url`,
`widget`.

The plugin also registers wildcard `*`, so an older/private/third-party tag such
as `<xt:some_other_service ... />` is still dispatched to the adapter. Set
`acceptUnknownXtTags: false` if you want an exact allow-list instead.

## Important compatibility boundary

This example is **syntax/dispatch compatible**, not a bundled clone of XtGem's
server services. Features such as file lists, counters, auth, blog, forum,
guestbook, RSS and widgets require application-owned data/services. Implement
`XtGemAdapterInterface` to map each tag to your own backend.

This separation is intentional: the core remains an independent XtScript Next for PHP engine.

## Supported call shapes

```html
<xt:filelist folder="/files" per_page="10" />
<xt:include file="/header.html" />
<xt:include file="/legacy-header.html">
<xt:custom flag enabled=true />
<xt:widget id="example">body</xt:widget>
```

Attributes may use double quotes, single quotes, unquoted values or boolean-style
names. Tag names are case-insensitive. Nested paired XT tags are resolved from the
inside out.

When a `SecurityPolicyInterface` is installed, allow-list XT tags by their
`xt:name` identity, for example `xt:filelist` or `xt:url`.

Run:

```bash
php examples/xtgem-compatibility/example.php
```
