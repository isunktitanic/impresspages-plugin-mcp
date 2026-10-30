# MCP Server for ImpressPages

Lets AI assistants such as **Claude** read, and if you allow it edit, an ImpressPages 5 site's pages over the **Model Context Protocol** (MCP). The site becomes a remote MCP server (Streamable HTTP transport, JSON-RPC 2.0) at `https://your-site/mcp`.

Tokens are **read-only** by default. A token you allow to edit still can't publish anything: title and SEO changes wait for you to apply them, and text changes are saved as the page's unpublished draft for you to publish.

## Features

- Tools: list languages and menus, list pages, get a page (title, URL, SEO fields, text content), search pages.
- Optional editing per token: propose title and SEO changes, and edit Text and Heading widgets as drafts. Never published by the assistant.
- A review screen to apply or reject proposals, and a record of every edit with its before and after.
- API tokens created and revoked in the admin. Only a SHA-256 hash of each token is stored; the token is shown once.
- HTTPS required by default.
- Per-token rate limit, a throttle on failed sign-ins and a request log you can read in the admin.
- Hidden and password-protected pages stay out unless you include them.

## Requirements

ImpressPages 5.1 (the PHP 8 community fork) on PHP 8.1 or newer, MySQL/MariaDB or SQLite.

## Install

1. Copy this repository into your site's `Plugin` folder as **`Plugin/Mcp`** (the folder name must be `Mcp`):
   ```bash
   git clone https://github.com/isunktitanic/impresspages-plugin-mcp.git Plugin/Mcp
   ```
2. In the admin, open **Plugins**, select **MCP Server** and click **Activate**. This creates three tables: `mcp_token`, `mcp_log` and `mcp_change`.
3. Open **MCP Server** in the admin menu (under Plugins), enter a label such as "Claude on my laptop" and click **Create token**. Tick **Allow editing** only if this assistant should be able to suggest changes. Copy the token straight away: it starts with `ipmcp_` and can't be shown again.

**Updating from 1.0:** copy the new files over, then click **Deactivate** and **Activate** on the Plugins screen once. This adds the `mcp_change` table; tokens and settings are kept.

Only administrators with permission for the MCP Server plugin can create and revoke tokens (Administrators → permissions).

## Connect Claude

### Claude Code

```bash
claude mcp add --transport http mysite https://your-site/mcp --header "Authorization: Bearer ipmcp_..."
```

The token page shows this command with your address and token filled in. Then ask Claude things like "list the pages on mysite" or "check the meta descriptions on mysite and suggest better ones".

### Claude Desktop

Claude Desktop connects to remote servers that need a header through the `mcp-remote` bridge (needs Node.js). In **Settings → Developer → Edit Config** add:

```json
{
  "mcpServers": {
    "mysite": {
      "command": "npx",
      "args": ["-y", "mcp-remote", "https://your-site/mcp", "--header", "Authorization:${MCP_AUTH}"],
      "env": { "MCP_AUTH": "Bearer ipmcp_..." }
    }
  }
}
```

Restart Claude Desktop. The token sits in an environment variable because some systems mangle spaces in arguments.

### Claude.ai custom connectors

Connectors added on claude.ai sign in with OAuth, which this plugin doesn't offer yet. Use Claude Code or Claude Desktop for now.

### Any other MCP client

Point it at `https://your-site/mcp` with the header `Authorization: Bearer ipmcp_...`. The server speaks protocol versions 2025-11-25, 2025-06-18, 2025-03-26 and 2024-11-05, answers with plain JSON (no event stream) and keeps no sessions.

## Tools

| Tool | What it returns |
|---|---|
| `list_languages` | Languages with code, title, home URL and their menus. |
| `list_menus` | Menus (top-level page trees) with alias and page count. Optional `languageCode`. |
| `list_pages` | Pages in menu order with id, title, URL, parent and depth. Optional `languageCode`, `menu`, `parentId`, `limit`, `offset`. |
| `get_page` | One page by `id` or `url`: SEO fields (meta title, description, keywords), the published text as plain text and per widget, and whether there are unpublished changes. `includeHtml` adds the HTML of text widgets. |
| `search_pages` | Pages whose title, SEO fields or published text contain `query`, with a snippet. Optional `languageCode`, `limit`. |

Tokens with editing allowed also get:

| Tool | What it does |
|---|---|
| `get_page` with `version: "draft"` | Reads the latest unpublished version and its widget ids, plus any proposals still waiting for review. |
| `propose_page_fields` | Proposes a new `title`, `metaTitle`, `description` and/or `keywords` for page `id`. Stored for review; nothing changes on the site. A newer proposal from the same token for the same page replaces the older one. |
| `update_text_widget` | Replaces the HTML of a Text widget (`pageId`, `widgetId`, `html`) in the page's draft. |
| `update_heading_widget` | Changes a Heading widget's `title` (plain text) and optionally `level` (1-6) in the page's draft. |

Text comes from the widgets of each page's **published** revision. Heading and Text widgets give their text; other widgets give their titles and captions; HTML widgets are left out because they hold code. Text that a theme prints from its own templates isn't part of a page's content.

## Reviewing edits

ImpressPages keeps unpublished drafts of a page's content, but a page's title and SEO fields go live as soon as they're saved. So the two kinds of edit are handled differently:

- **Title and SEO fields** appear under **Waiting for review** on the MCP Server admin page, with the current and proposed values side by side. **Apply to the live page** writes them; **Reject** drops them. If someone changed a field in the admin after the proposal was made, that field is left alone and you're told which.
- **Text and headings** go into the page's unpublished draft. If the page has no draft yet, one is made from the published version. Open the page in the admin: it shows "Unpublished changes". Check the text and click **Publish**, or discard the draft there. Until then visitors see the published page.

**Recent edits** on the same admin page lists every edit with its token and status (`draft`, `pending`, `applied`, `rejected`, `replaced`); the before and after values are kept in the `mcp_change` table.

## Settings

On the Plugins screen, under MCP Server:

| Setting | What it does |
|---|---|
| Enable the MCP endpoint | Off: `/mcp` answers "404 Not Found". Tokens are kept. |
| Require HTTPS | On by default. Plain HTTP is refused so tokens never travel unencrypted. Requests from the same computer (127.0.0.1 or ::1) are always allowed, for local testing. |
| Requests per minute per token | Default 60. Further requests in that minute get a "rate limit exceeded" error. |
| Include hidden and password-protected pages | Off by default, so assistants see only what a visitor sees. |
| Keep the request log for (days) | Default 30. Older entries are deleted. |

## Security notes

Giving an AI assistant access to your site is a door into it, so this plugin is careful about who comes through and what they can do.

- **Read-only unless you say otherwise.** A token can only edit if you tick "Allow editing" for it, and you can switch that off again at any time. Read-only tokens don't even see the editing tools; if one calls them anyway, the request is refused and logged as `denied`.
- **Nothing is published by an assistant.** There's no tool that publishes a page or writes a live title or SEO field. Those need an administrator's click.
- **HTML from assistants is cleaned.** Text widget HTML goes through an allow-list: paragraphs, h2-h6, lists, links, bold, italic, underline, strikethrough, sub/superscript, blockquotes and tables. Scripts, styles, iframes, forms and SVG are dropped with their content; other tags are unwrapped; every attribute is removed except a link's `href`, which must be `http`, `https`, `mailto`, `tel` or a relative link. Heading text and page fields are stored as plain text.
- **Every edit is recorded** with the token, the page, and the values before and after.
- **Treat a token like a password.** Anyone who has it can read whatever the plugin shows. Give each assistant or computer its own token, so you can revoke one without touching the others. Revoking takes effect on the next request.
- **Tokens are stored hashed.** A token is 256 random bits; the database keeps only its SHA-256 hash and a short hint (`ipmcp_1a2b…9f0e`) to tell tokens apart. A copy of the database doesn't give anyone a working token.
- **HTTPS.** Keep "Require HTTPS" on for any public site. If TLS ends at a proxy or CDN, the `X-Forwarded-Proto: https` header is accepted; a client could fake that header, but it would only expose its own token.
- **Web pages can't use the endpoint.** Requests carrying a browser `Origin` from another host are refused (protection against DNS rebinding and cross-site requests). MCP clients don't send `Origin`.
- **Guessing is throttled.** After 20 failed sign-ins from one address in 10 minutes, further attempts from that address with a wrong token are refused for 10 minutes. Valid tokens from the same address keep working.
- **The request log** records time, token, client address (the direct connection, not `X-Forwarded-For`), method, tool, result and the tool arguments (for example search words), shortened to 255 characters. It never stores tokens. Read it under MCP Server in the admin.
- **What's visible.** By default only pages a visitor can open: hidden pages, pages under a hidden menu or parent, and password-protected pages are left out. Unpublished drafts are never shown.
- **Assistants read your content as data.** If a page contains text written to manipulate an AI ("ignore your instructions…"), the assistant will read it like any other text. With read-only access the harm is limited to what it tells you. With editing on, such text could steer the assistant into proposing changes you didn't ask for, which is one more reason everything waits for your review. Give editing only to tokens that need it.
- **Authorization header on Apache.** Some Apache/CGI setups drop the `Authorization` header. If every request gets "401" with a valid token, add to `.htaccess`:
  ```apache
  CGIPassAuth On
  ```
  or, on older Apache: `RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]`.

## Test with curl

```bash
curl -s https://your-site/mcp \
  -H "Authorization: Bearer ipmcp_..." \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"list_pages","arguments":{}}}'
```

`initialize`, `ping`, `tools/list` and `tools/call` are supported. Notifications get "202 Accepted"; GET gets "405 Method Not Allowed" because the server doesn't open event streams.

## Uninstall

Deactivate the plugin on the Plugins screen, then delete it. Deleting drops the token, log and change tables. Drafts already saved in pages stay there.

## License

MIT, see [LICENSE](LICENSE).
