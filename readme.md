# Paid Memberships Pro GitHub Integration
# =========================

This plugin integrates Paid Memberships Pro with GitHub, allowing you to manage membership levels and sync them with GitHub organization teams.

## Features
- Sync PMPro membership levels with GitHub organization teams.
- Configure the mapping of PMPro membership levels to GitHub teams.
- Automatically accept team invitations.
- Remove users from teams when they change membership levels.
- Bulk sync functionality.
- Verbose logging for debugging.
- Manual sync option for immediate updates.
- Shortcode for rendering a "Connect" button to display to users.

## Important Details
- The plugin requires a GitHub OAuth app to be set up.
- The GitHub OAuth app must have the necessary permissions to manage teams and accept invitations.
- The plugin uses the GitHub API (via a PAT with org admin permission) to manage team memberships based on PMPro membership levels.
- The plugin will automatically remove users from teams if they are not in the PMPro membership levels during a sync.
- If a user revokes access to the token or the token expires, they will remain in their teams until the next sync (upon a membership level change or a scheduled/manual sync).
- Only currently available membership levels show up on the Teams Mapping page

## FAQ

### What happens to users on a membership level that was previous mapped to a team when the admin hides that level from new signups?

When a membership level is hidden from new signups, users who are already on that level will remain in the team until they change their membership level or the team mapping is updated. The plugin does not automatically remove users from teams based on visibility settings of membership levels.

Essentially, the previous team mapping remains intact for existing users, and they will not be removed from the team unless the admin explicitly changes the team mapping or the user changes their membership level.

However, if the admin updates the team mapping to remove that level or changes the team associated with it, users will be removed from the team during the next sync. This will happen even if the admin simply clicks "Save Changes" on the Teams Mapping page without making any changes to the mappings - as the mappings will no longer show the hidden membership level.

Example: What happens to existing users when we hide their membership level?
- The level disappears from the Team Mapping page
- No change to existing members on that level
- When member on that level cancels, they’re removed from that level’s teams
- If admin adds a member to that level, they are added back to that level - provided the admin settings haven't been changed. 
  - After saving the level mapping then admin adding someone to that level, they don’t get added
  - So if admin adding someone to an unavailable level, need to:
      1. Make that level available so that it appears in the team mapping
      2. Save changes to team mapping 
      3. Then make it unavailable again
      4. Then admin add the person to that level
  - If someone is going through the checkout for a previously unavailable level:
      1. After enabling the level, configure the team mapping and save
      2. Then direct the person to the checkout