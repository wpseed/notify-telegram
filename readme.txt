=== Notify Telegram ===
Contributors: wpseed
Tags: telegram, notifications, webhook, email, monitoring
Requires at least: 6.5
Tested up to: 7.1
Stable tag: 0.1.0
Requires PHP: 8.2
License: MIT
License URI: https://opensource.org/licenses/MIT

Sends WordPress events — registrations, failed logins, new comments — to Telegram, email or a webhook.

== Description ==

Notify Telegram watches a few things that happen on a WordPress site and sends a message about them
to the channels you choose. It talks to the Telegram Bot API directly: no account on another service,
no relay in between, and nothing leaves your site except the messages you asked for.

A message goes out when:

* a **new user registers** — so unexpected registrations are noticed while they are still rare;
* a **login attempt fails** — with the submitted username and the client IP address, which is what
  catches a brute-force run early;
* a **comment is published** — with the author, the post and the first 200 characters of the text.
  Comments waiting for moderation are not reported, and neither is spam.

Every event has its own switch and its own message text. A template uses `%placeholder%` tokens that
the event fills in:

* new user: `%display_name%`, `%user_email%`, `%roles%`
* failed login: `%username%`, `%ip%`
* new comment: `%post_title%`, `%author%`, `%content%`, `%post_url%`

so the message reads the way you want it, in whatever language the site speaks. A placeholder an
event does not provide is refused when the template is saved, so a typo cannot reach your chat.

= Channels =

* **Telegram** — one bot token and as many chat IDs as you need, so a private chat, a group and a
  channel can be notified at the same time. A message longer than Telegram's limit is sent in
  several parts instead of being cut off, and a rate-limit answer from the API is respected: the
  plugin waits the number of seconds Telegram asks for.
* **Email** — one or more recipients, sent through WordPress' own mailer (`wp_mail()`).
* **Webhook** — one or more URLs. The rendered message goes out as a JSON POST, for a tool of your
  own, a monitoring system or a chat service with its own API.

A channel that is switched on but has no credentials yet is skipped, with a line in the log, instead
of breaking the request that triggered it.

= Delivery, retries and the log =

Notifications are delivered through WP-Cron, so the page that triggered an event is not held up by a
slow API. A failed delivery is retried up to three times; when the API says how long to wait, that
number is used instead of the default pause. A failure that retrying cannot fix — a token revoked in
BotFather, a chat the bot was removed from — is recorded as final and not retried. Every attempt,
successful or not, leaves one line in the log on the settings screen (the last 20 entries), so
"did it send?" always has an answer.

= Setting it up =

1. **Create a bot and copy its token.** Talk to [@BotFather](https://t.me/BotFather); the
   [Telegram documentation](https://core.telegram.org/bots/features#botfather) describes the steps.
   For a group or a channel, add the bot to it first — and make it an administrator if the channel
   restricts who may post.
2. **Find the chat ID.** Send the bot a message, then open
   `https://api.telegram.org/bot<YOUR_TOKEN>/getUpdates` in a browser: the `chat` object in the
   answer contains `id`. A chat ID that belongs to a group or a channel is negative.
3. **Paste the token and the chat IDs** into *Tools → Notify Telegram → Settings* and press the test
   button. It sends one message through every configured channel, so a wrong token or chat ID shows
   up before a real event does.

= Extending it =

Three filters are available for a site that needs more than the built-in events and channels:

* `notify_telegram_event_sources` — register additional event sources;
* `notify_telegram_channels` — register additional channels;
* `notify_telegram_send_async` — return `false` to deliver inside the request instead of through
  WP-Cron.

== Installation ==

1. Install the plugin from the plugin directory, or upload the folder to `wp-content/plugins/`.
2. Activate it under *Plugins*.
3. Open *Tools → Notify Telegram* and configure at least one channel.

Nothing else is required: the plugin adds no page, no shortcode and no theme changes.

== Frequently Asked Questions ==

= Do I need a Telegram account? =

Only for the Telegram channel. Email and webhook work without one, and they carry the same events.

= Can I send messages to a group or a channel? =

Yes. Add the bot to the group, or make it an administrator of the channel, then use that chat's ID —
a negative number. A single bot can post to a private chat, several groups and a channel at once:
add one chat ID per line.

= The test message did not arrive. What should I check? =

The log lists every attempt with its reason. The usual ones are:

* `401 Unauthorized` — the bot token is wrong or was revoked in BotFather;
* `400 Bad Request: chat not found` — the chat ID is wrong, or the bot was never added to that chat;
* `403 Forbidden` — the bot was blocked, or it is not allowed to post in that channel.

= Are notifications sent immediately? =

They are queued and go out on the next request to the site, which is what keeps a slow API from
slowing the site down. On a site with no visitors that can take a while — such sites need a real cron
for WP-Cron. Returning `false` from `notify_telegram_send_async` delivers inside the same request
instead.

= Can I be notified about something else, such as a form submission or an order? =

Not in this version. The event list is deliberately short, and every event is a hook of its own, so
another one can be added today with the `notify_telegram_event_sources` filter.

= Which versions does it need? =

WordPress 6.5 or newer and PHP 8.2 or newer.

= Where is the configuration kept? =

In two options: `notify_telegram_settings` (channels, events and templates) and `notify_telegram_log`
(the last 20 delivery attempts). Neither is sent anywhere.

== Changelog ==

= 0.1.0 =
* First release.
* Channels: Telegram (several chats per bot), email and webhook.
* Events: new user registration, failed login with the client IP, published comment.
* Per-event switches and message templates with `%placeholder%` tokens.
* Queued delivery with three attempts, respect for the API's own retry delay, and final failures
  that are not retried.
* A log of the last 20 attempts and a test button that sends through every configured channel.

== Upgrade Notice ==

= 0.1.0 =
First release.
