# Reconnect Instagram on the office PC

Account: **@tesla69ugh**  
Instagram professional account ID: **17841415000086979**  
Linked Facebook Page: **Nix The Dev** (`1279105108617294`)  
Meta developer app: **Fact Studio** (`1670985727959280`)

## Instructions for the office coding assistant

> Read this file and WORK-PC-2026-11-04.md. Keep the existing office database, connections, video library and installed models. Reconnect @tesla69ugh using the Instagram API with Facebook Login. First inspect whether the saved Instagram token still works; never print tokens or app secrets. If authorization is needed, guide the user through Graph API Explorer and have them save the token directly in the local Connections form. Confirm granted Instagram permissions and verify the linked account username and ID with read-only Meta requests. Set the verified account ID, start a new tunnel on this office PC, and verify public dashboard/API/private-file access is blocked and signed video HEAD requests succeed. Do not download private video content through the tunnel for testing. Keep automatic publishing and scheduling off, and do not publish a test post or retry a live job during reconnection. Report credential verification, tunnel checks and actual publishing as separate results; saved settings alone do not prove successful publishing.

## 1. Update code without moving private files

Once the laptop changes have been pushed to GitHub, update the existing office checkout:

```powershell
cd C:\xampp\htdocs\fact
git status --short
git pull --ff-only
$env:Path = 'C:\xampp\php;' + $env:Path
powershell -ExecutionPolicy Bypass -File scripts/start-studio.ps1
```

Use the actual office project folder if different. Preserve local changes if Git reports a conflict or refuses the update; do not reset or delete storage/tools. These instructions and the tunnel scripts must be present in the pulled version before proceeding. At the time this guide was written, the laptop changes had not yet been pushed.

No download from the home laptop is needed for this route. The office's existing database and AI installations stay in place. Git excludes credentials, so obtain new Instagram authorization at work if the saved token is missing or invalid. Do not restore a laptop backup over the office database.

## 2. Generate Instagram authorization if needed

1. Open [Meta Graph API Explorer](https://developers.facebook.com/tools/explorer/).
2. Select the **Fact Studio** app, **graph.facebook.com**, and **User Token**. This Instagram integration accepts a Facebook Login user token with the required Instagram access; do not switch the API host to graph.instagram.com.
3. Select `instagram_basic`, `instagram_content_publish`, `pages_show_list` and `pages_read_engagement`. Keep any existing permissions needed for the Facebook connection.
4. Click **Generate Access Token** and approve access to the intended Page and Instagram account.
5. Open [FACT Studio Connections](http://localhost:8085/?view=connections). In **Facebook & Instagram**, paste the token into **Instagram publishing access token**, set the professional account ID to **17841415000086979**, and save. Do not paste the Instagram token into the Facebook Page access token field.

If the Instagram permissions are unavailable, open **My Apps → Fact Studio → Use cases → Manage messaging & content on Instagram → Customize → API setup with Facebook login → Add required content permissions**. This use case and its content permissions were already added from the laptop, so inspect the existing configuration first.

If authorization is rejected, check that @tesla69ugh remains a Business/Creator account linked to Nix The Dev, that the signed-in user can manage it, and that the user has the required app access while Fact Studio is in development. Tokens can expire or be revoked, including after logout; do not promise permanent access.

## 3. Verify the account before enabling publishing

The assistant should use the saved Instagram token privately as an Authorization Bearer header for read-only requests:

```text
GET /me/permissions
GET /1279105108617294?fields=id,name,instagram_business_account{id,username}
```

Use `https://graph.facebook.com/` and the Graph API version configured in Connections. Confirm Instagram basic/publishing permissions are granted and the returned account is **tesla69ugh**, ID **17841415000086979**. A missing Instagram field can reflect missing permissions or account access; it does not by itself prove the Page is unlinked.

The Facebook publishing token is a separate setting. If it is invalid, obtain a **Page token for Nix The Dev** and save it in **Facebook Page access token**. Do not assume that a valid Instagram user token validates Facebook Reel publishing.

## 4. Start the office PC's video tunnel

```powershell
powershell -ExecutionPolicy Bypass -File scripts/start-instagram-tunnel.ps1
php tests/tunnel.php
```

The script downloads verified cloudflared if missing, starts it in the background and saves the office tunnel address in Connections. Do not copy the laptop's HTTPS address: it serves files from the laptop. The header-only test requires an existing rendered video and makes no publishing request.

Keep the PC awake, FACT Studio running on port 8085 and the tunnel process running while Instagram fetches media. After restarting Windows, start FACT Studio and run the tunnel script again; it saves the new temporary URL. A permanent domain/tunnel can be configured separately later.

## 5. Report readiness accurately

Confirm the account/token and tunnel checks separately. Leave **Publish to Instagram**, **Automatic publishing** and **Daily production** off during setup. Enable them only when the user is ready to publish reviewed live content. Demo and free preview jobs cannot publish. Do not activate scheduling on both PCs; their databases do not synchronize production queues.

No Instagram post has been made as part of the laptop setup. A successful real upload remains a separate publishing test.
