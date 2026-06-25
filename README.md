# Git Puller

Git Puller is a WordPress admin plugin for cloning and force-pulling multiple Git-backed WordPress plugins.

## Workflow

1. Activate the plugin in WordPress.
2. Open `Tools > Git Puller`.
3. Add a repository URL in this format:

   ```text
   https://username:password@repo
   ```

4. Add the branch name to track.
5. Click `Clone and Add`.
6. Later, click `Force Pull` for that project.

On pull, the plugin runs:

```text
git fetch origin <branch> --prune
git checkout <branch>
git reset --hard origin/<branch>
git clean -fd
```

Each repository must contain a valid WordPress plugin file with a plugin header. After the pull, Git Puller detects that plugin file, deactivates it if active, and activates it again so database migrations and activation hooks can run.

## Notes

- Repository URLs with credentials are stored in the WordPress database because Git needs them for future pulls.
- Only administrators with `manage_options` can use the tool.
- Clones are placed under `WP_PLUGIN_DIR` using the repository name as the folder slug.
