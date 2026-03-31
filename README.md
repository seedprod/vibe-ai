# Vibe AI - WordPress MCP Server for Claude, ChatGPT & AI Assistants

**Your favorite AI just learned WordPress.** Manage your WordPress site from Claude, ChatGPT, Cursor, or any MCP-compatible AI client.

Create posts, upload images, manage plugins, edit themes, and automate site tasks — all through natural conversation. No wp-admin needed.

[Get Started](https://wpvibe.ai) | [Documentation](https://wpvibe.ai/docs/) | [Feature Requests](https://wpvibe.ai/feature-requests/)

## How It Works

Vibe AI is a WordPress MCP (Model Context Protocol) server. It connects your self-hosted WordPress site to any AI assistant that supports MCP.

```
Your AI (Claude, ChatGPT, Cursor, etc.)
  → Vibe AI MCP Server (hosted on Cloudflare)
    → Your WordPress Site (via REST API)
```

1. Install the Vibe AI plugin on your WordPress site
2. Click the one-click authorization link — no passwords to copy
3. Start managing your site from your AI assistant

Your AI handles the WordPress REST API calls behind the scenes. You just talk.

## Features

- **AI Content Management** — Create, edit, publish posts and pages through conversation
- **Media Uploads** — Upload images from any URL directly to your media library
- **Stock Photo Search** — Search Unsplash for images and set featured images without leaving chat
- **WordPress REST API** — Full access to any WordPress REST endpoint including custom post types
- **Abilities API** — Discover and run plugin abilities on WordPress 6.9+ sites
- **Live Reload** — Smart browser notifications when your AI makes changes, with direct links
- **Theme File Browsing** — Let your AI read and analyze your theme structure (read-only)
- **Multi-Client Support** — Works with Claude (claude.ai), ChatGPT, Claude Desktop, Cursor, Windsurf, and any MCP client

## Installation

### From WordPress.org (coming soon)

Search for "Vibe AI" in your WordPress plugin directory.

### Manual Install

1. Download the [latest release](https://github.com/seedprod/vibe-ai/releases/latest)
2. In WordPress, go to Plugins → Add New → Upload Plugin
3. Upload the zip file and activate
4. Click "Connect to WPVibe" in the plugin settings

## Connecting Your AI

### Claude (claude.ai)

Vibe AI works directly from claude.ai in your browser — no desktop app needed. Add the MCP server URL in your Claude settings.

### ChatGPT

Connect via ChatGPT's MCP integration. Same hosted server, same one-click auth.

### Claude Desktop / Cursor / Windsurf

Add to your MCP configuration:

```json
{
  "mcpServers": {
    "wpvibe": {
      "url": "https://mcp.wpvibe.ai/sse"
    }
  }
}
```

## Example Prompts

```
"Create a blog post about summer travel tips and publish it"
"Find a featured image for my latest post"
"Upload this image and set it as the featured image for post 42"
"List all draft posts"
"Update the homepage title to Welcome to My Site"
"What plugins are installed on my site?"
"Show me my site info"
```

## Security

- **Encrypted credentials** — AES-256-GCM encryption at rest with per-site salting
- **WordPress capabilities** — Every action respects WordPress user permissions
- **One-click auth** — Uses WordPress Application Passwords (built into WP 5.6+)
- **No tracking** — Your content stays on your server. We only relay requests.

## Requirements

- WordPress 6.0+
- PHP 7.4+
- An AI client that supports MCP (Claude, ChatGPT, Cursor, Windsurf, etc.)

## Built by SeedProd

Vibe AI is built by the team behind [SeedProd](https://www.seedprod.com), trusted by over 1,000,000 WordPress sites since 2012.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE) for details.

## Links

- [Website](https://wpvibe.ai)
- [Documentation](https://wpvibe.ai/docs/)
- [Getting Started Guide](https://wpvibe.ai/docs/getting-started/)
- [Feature Requests](https://wpvibe.ai/feature-requests/)
- [Support](https://wpvibe.ai/support/)
