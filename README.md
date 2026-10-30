# MCP Server for ImpressPages

Lets AI assistants such as **Claude** read an ImpressPages 5 site's pages over the **Model Context Protocol** (MCP). The site becomes a remote MCP server (Streamable HTTP transport, JSON-RPC 2.0) at `https://your-site/mcp`.

This version is **read-only**: assistants can list and read pages and search them, but can't change anything.

## Features

- Tools: list languages and menus, list pages, get a page (title, URL, SEO fields, text content), search pages.
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
2. In the admin, open **Plugins**, select **MCP Server** and click **Activate**. This creates two tables, `mcp_token` and `mcp_log`.
3. Open **MCP Server** in the admin menu (under Plugins), enter a label such as "Claude on my laptop" and click **Create token**. Copy the token straight away: it starts with `ipmcp_` and can't be shown again.

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

Text comes from the widgets of each page's **published** revision. Heading and Text widgets give their text; other widgets give their titles and captions; HTML widgets are left out because they hold code. Text that a theme prints from its own templates isn't part of a page's content.

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

- **Read-only.** No tool writes anything. The tool list says so (`readOnlyHint`), and there is no code path that changes pages.
- **Treat a token like a password.** Anyone who has it can read whatever the plugin shows. Give each assistant or computer its own token, so you can revoke one without touching the others. Revoking takes effect on the next request.
- **Tokens are stored hashed.** A token is 256 random bits; the database keeps only its SHA-256 hash and a short hint (`ipmcp_1a2b…9f0e`) to tell tokens apart. A copy of the database doesn't give anyone a working token.
- **HTTPS.** Keep "Require HTTPS" on for any public site. If TLS ends at a proxy or CDN, the `X-Forwarded-Proto: https` header is accepted; a client could fake that header, but it would only expose its own token.
- **Web pages can't use the endpoint.** Requests carrying a browser `Origin` from another host are refused (protection against DNS rebinding and cross-site requests). MCP clients don't send `Origin`.
- **Guessing is throttled.** After 20 failed sign-ins from one address in 10 minutes, further attempts from that address with a wrong token are refused for 10 minutes. Valid tokens from the same address keep working.
- **The request log** records time, token, client address (the direct connection, not `X-Forwarded-For`), method, tool, result and the tool arguments (for example search words), shortened to 255 characters. It never stores tokens. Read it under MCP Server in the admin.
- **What's visible.** By default only pages a visitor can open: hidden pages, pages under a hidden menu or parent, and password-protected pages are left out. Unpublished drafts are never shown.
- **Assistants read your content as data.** If a page contains text written to manipulate an AI ("ignore your instructions…"), the assistant will read it like any other text. With read-only access the harm is limited to what it tells you; keep it in mind before trusting summaries of pages other people edit.
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

Deactivate the plugin on the Plugins screen, then delete it. Deleting drops the token and log tables.

## Roadmap

A later version may let a token **edit** page titles, SEO fields and text widgets, behind an explicit "allow editing" switch per token. Changes would be saved as drafts for a person to review and publish, never published by the assistant.

## License

MIT, see [LICENSE](LICENSE).
