## 1.0
- Initial release of the GitHub OAuth app for syncing membership levels with Organisation Teams.

## 1.1.0 
- Auto accept invitations using elevated user permissions requested during OAuth flow.

### 1.1.1
- Send one invite per team and do a full sync x minutes after completing the invitations flow. 

### 1.1.2
- Remove users from teams that are not in the PMPro membership levels upon level change.

### 1.1.3
- Remove users from teams upon detection of an expired token (401 in `github_api_request()`).
-If user revokes access to the token, or the token is expired, the user will remain in their Teams until the next sync. This happens upon a membership level change or a scheduled or manual sync. The connect button will show "No teams found."

### 1.1.4 
- Add bulk sync button to the settings page.
- Add cancel bulk sync button to the settings page.
- Add verbose logging flag.
- 
## TODO:

### In class-shortcodes.php, token/team fetch failure could have unintended consequences.

If `get_user_teams()` fails (e.g., network error), it returns an empty array, which will show "No teams found." This is correct, but if the failure is due to a temporary GitHub outage, the user may be prompted to reconnect unnecessarily. You might want to distinguish between a 401 (token revoked) and other errors.

