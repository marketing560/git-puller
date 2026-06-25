<?php

if (!defined('ABSPATH')) {
    exit;
}

class Git_Puller
{
    private const TABLE = 'git_puller_projects';

    private static ?Git_Puller $instance = null;

    public static function instance(): Git_Puller
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public static function activate(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = self::table_name();
        $charset_collate = $wpdb->get_charset_collate();

        dbDelta(
            "CREATE TABLE {$table} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                repo_url TEXT NOT NULL,
                repo_label VARCHAR(255) NOT NULL,
                branch_name VARCHAR(191) NOT NULL,
                local_path TEXT NOT NULL,
                plugin_file VARCHAR(255) DEFAULT '' NOT NULL,
                last_commit_hash VARCHAR(40) DEFAULT '' NOT NULL,
                last_commit_subject TEXT NOT NULL,
                last_status VARCHAR(20) DEFAULT 'created' NOT NULL,
                last_message TEXT NOT NULL,
                last_pulled_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                KEY branch_name (branch_name),
                KEY last_status (last_status)
            ) {$charset_collate};"
        );

        update_option('git_puller_db_version', GIT_PULLER_VERSION);
    }

    private function __construct()
    {
        add_action('admin_init', [$this, 'maybe_upgrade_schema']);
        add_action('admin_menu', [$this, 'register_admin_page']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('admin_post_git_puller_add_project', [$this, 'handle_add_project']);
        add_action('admin_post_git_puller_pull_project', [$this, 'handle_pull_project']);
        add_action('admin_post_git_puller_delete_project', [$this, 'handle_delete_project']);
    }

    public function maybe_upgrade_schema(): void
    {
        if (get_option('git_puller_db_version') !== GIT_PULLER_VERSION) {
            self::activate();
        }
    }

    public function register_admin_page(): void
    {
        add_management_page(
            __('Git Puller', 'git-puller'),
            __('Git Puller', 'git-puller'),
            'manage_options',
            'git-puller',
            [$this, 'render_admin_page']
        );
    }

    public function enqueue_admin_assets(string $hook): void
    {
        if ($hook !== 'tools_page_git-puller') {
            return;
        }

        wp_enqueue_style(
            'git-puller-admin',
            GIT_PULLER_PLUGIN_URL . 'assets/css/admin.css',
            [],
            GIT_PULLER_VERSION
        );
    }

    public function render_admin_page(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'git-puller'));
        }

        $notice = $this->read_notice();
        $projects = $this->get_projects();
        ?>
        <div class="wrap git-puller">
            <h1><?php esc_html_e('Git Puller', 'git-puller'); ?></h1>

            <?php if ($notice) : ?>
                <div class="notice notice-<?php echo esc_attr($notice['type']); ?> is-dismissible">
                    <p><?php echo esc_html($notice['message']); ?></p>
                </div>
            <?php endif; ?>

            <section class="git-puller-panel">
                <h2><?php esc_html_e('Add Project', 'git-puller'); ?></h2>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="git-puller-form">
                    <?php wp_nonce_field('git_puller_add_project'); ?>
                    <input type="hidden" name="action" value="git_puller_add_project">

                    <label>
                        <span><?php esc_html_e('Repository URL', 'git-puller'); ?></span>
                        <input
                            type="url"
                            name="repo_url"
                            placeholder="https://username:password@example.com/project/plugin.git"
                            required
                        >
                    </label>

                    <label>
                        <span><?php esc_html_e('Branch', 'git-puller'); ?></span>
                        <input type="text" name="branch_name" placeholder="main" required>
                    </label>

                    <?php submit_button(__('Clone and Add', 'git-puller'), 'primary', 'submit', false); ?>
                </form>
                <p class="description">
                    <?php esc_html_e('The URL is stored because it is needed for future pulls. Keep this admin page limited to trusted administrators.', 'git-puller'); ?>
                </p>
            </section>

            <section class="git-puller-panel">
                <h2><?php esc_html_e('Projects', 'git-puller'); ?></h2>
                <?php if (!$projects) : ?>
                    <p><?php esc_html_e('No projects have been added yet.', 'git-puller'); ?></p>
                <?php else : ?>
                    <table class="widefat striped git-puller-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Repository', 'git-puller'); ?></th>
                                <th><?php esc_html_e('Branch', 'git-puller'); ?></th>
                                <th><?php esc_html_e('Local Path', 'git-puller'); ?></th>
                                <th><?php esc_html_e('Plugin File', 'git-puller'); ?></th>
                                <th><?php esc_html_e('Last Deployed Commit', 'git-puller'); ?></th>
                                <th><?php esc_html_e('Status', 'git-puller'); ?></th>
                                <th><?php esc_html_e('Last Pull', 'git-puller'); ?></th>
                                <th><?php esc_html_e('Actions', 'git-puller'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($projects as $project) : ?>
                                <tr>
                                    <td>
                                        <strong><?php echo esc_html($project->repo_label); ?></strong><br>
                                        <code><?php echo esc_html($this->mask_repo_url($project->repo_url)); ?></code>
                                    </td>
                                    <td><?php echo esc_html($project->branch_name); ?></td>
                                    <td><code><?php echo esc_html($project->local_path); ?></code></td>
                                    <td><?php echo $project->plugin_file ? '<code>' . esc_html($project->plugin_file) . '</code>' : '&mdash;'; ?></td>
                                    <td>
                                        <?php if (!empty($project->last_commit_hash)) : ?>
                                            <code><?php echo esc_html(substr($project->last_commit_hash, 0, 12)); ?></code>
                                            <?php if (!empty($project->last_commit_subject)) : ?>
                                                <small><?php echo esc_html($project->last_commit_subject); ?></small>
                                            <?php endif; ?>
                                        <?php else : ?>
                                            &mdash;
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="git-puller-status git-puller-status-<?php echo esc_attr($project->last_status); ?>">
                                            <?php echo esc_html($project->last_status); ?>
                                        </span>
                                        <?php if ($project->last_message) : ?>
                                            <small><?php echo esc_html($project->last_message); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo $project->last_pulled_at ? esc_html($project->last_pulled_at) : '&mdash;'; ?></td>
                                    <td class="git-puller-actions">
                                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                            <?php wp_nonce_field('git_puller_pull_project_' . $project->id); ?>
                                            <input type="hidden" name="action" value="git_puller_pull_project">
                                            <input type="hidden" name="project_id" value="<?php echo esc_attr($project->id); ?>">
                                            <?php submit_button(__('Force Pull', 'git-puller'), 'secondary small', 'submit', false); ?>
                                        </form>

                                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('<?php echo esc_js(__('Remove this project from the list? The cloned files will stay on disk.', 'git-puller')); ?>');">
                                            <?php wp_nonce_field('git_puller_delete_project_' . $project->id); ?>
                                            <input type="hidden" name="action" value="git_puller_delete_project">
                                            <input type="hidden" name="project_id" value="<?php echo esc_attr($project->id); ?>">
                                            <?php submit_button(__('Remove', 'git-puller'), 'link-delete small', 'submit', false); ?>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </section>
        </div>
        <?php
    }

    public function handle_add_project(): void
    {
        $this->guard_action('git_puller_add_project');

        $repo_url = isset($_POST['repo_url']) ? trim(sanitize_text_field(wp_unslash($_POST['repo_url']))) : '';
        $branch_name = isset($_POST['branch_name']) ? sanitize_text_field(wp_unslash($_POST['branch_name'])) : '';

        try {
            $this->validate_repo_url($repo_url);
            $this->validate_branch_name($branch_name);

            $local_path = $this->build_local_path($repo_url);
            if (file_exists($local_path)) {
                throw new RuntimeException(__('A project folder already exists for this repository.', 'git-puller'));
            }

            $clone = $this->run_git(['clone', '--branch', $branch_name, '--single-branch', $repo_url, $local_path]);
            if ($clone['code'] !== 0) {
                throw new RuntimeException($clone['output']);
            }

            $plugin_file = $this->detect_plugin_file($local_path);
            if ($plugin_file === '') {
                throw new RuntimeException(__('Repository cloned, but no WordPress plugin file was found in it.', 'git-puller'));
            }

            $commit = $this->get_deployed_commit($local_path);
            $this->insert_project($repo_url, $branch_name, $local_path, $plugin_file, $commit, 'cloned', __('Repository cloned successfully.', 'git-puller'));
            $this->redirect_with_notice('success', __('Project cloned and added.', 'git-puller'));
        } catch (Throwable $e) {
            $this->redirect_with_notice('error', $e->getMessage());
        }
    }

    public function handle_pull_project(): void
    {
        $project_id = isset($_POST['project_id']) ? absint($_POST['project_id']) : 0;
        $this->guard_action('git_puller_pull_project_' . $project_id);

        $project = $this->get_project($project_id);
        if (!$project) {
            $this->redirect_with_notice('error', __('Project not found.', 'git-puller'));
        }

        try {
            $this->force_pull($project);
            $this->redirect_with_notice('success', __('Project force-pulled and plugin reactivated.', 'git-puller'));
        } catch (Throwable $e) {
            $this->mark_project($project_id, 'error', $e->getMessage());
            $this->redirect_with_notice('error', $e->getMessage());
        }
    }

    public function handle_delete_project(): void
    {
        global $wpdb;

        $project_id = isset($_POST['project_id']) ? absint($_POST['project_id']) : 0;
        $this->guard_action('git_puller_delete_project_' . $project_id);

        $wpdb->delete(self::table_name(), ['id' => $project_id], ['%d']);
        $this->redirect_with_notice('success', __('Project removed from the list.', 'git-puller'));
    }

    private function force_pull(object $project): void
    {
        if (!is_dir($project->local_path . DIRECTORY_SEPARATOR . '.git')) {
            throw new RuntimeException(__('Local path is not a Git repository.', 'git-puller'));
        }

        $commands = [
            ['-C', $project->local_path, 'fetch', 'origin', $project->branch_name, '--prune'],
            ['-C', $project->local_path, 'checkout', $project->branch_name],
            ['-C', $project->local_path, 'reset', '--hard', 'origin/' . $project->branch_name],
            ['-C', $project->local_path, 'clean', '-fd'],
        ];

        foreach ($commands as $command) {
            $result = $this->run_git($command);
            if ($result['code'] !== 0) {
                throw new RuntimeException($result['output']);
            }
        }

        $plugin_file = $project->plugin_file ?: $this->detect_plugin_file($project->local_path);
        if ($plugin_file === '') {
            throw new RuntimeException(__('No WordPress plugin file was found in the project folder.', 'git-puller'));
        }

        if ($plugin_file) {
            $this->reactivate_plugin($plugin_file);
        }

        $commit = $this->get_deployed_commit($project->local_path);
        $this->mark_project($project->id, 'pulled', __('Force pull completed.', 'git-puller'), $plugin_file, true, $commit);
    }

    private function reactivate_plugin(string $plugin_file): void
    {
        if (!function_exists('deactivate_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        if (is_plugin_active($plugin_file)) {
            deactivate_plugins($plugin_file, true);
        }

        $result = activate_plugin($plugin_file);
        if (is_wp_error($result)) {
            throw new RuntimeException($result->get_error_message());
        }
    }

    private function run_git(array $arguments): array
    {
        $command = array_merge(['git'], $arguments);
        $descriptor_spec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptor_spec, $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException(__('Unable to start git process.', 'git-puller'));
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $code = proc_close($process);
        $output = trim($stdout . "\n" . $stderr);

        return [
            'code' => $code,
            'output' => $output !== '' ? $output : __('Git command completed.', 'git-puller'),
        ];
    }

    private function detect_plugin_file(string $local_path): string
    {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $plugin_root = wp_normalize_path(WP_PLUGIN_DIR);
        $local_path = wp_normalize_path($local_path);
        if (strpos($local_path, $plugin_root . '/') !== 0) {
            return '';
        }

        $relative_dir = trim(substr($local_path, strlen($plugin_root)), '/');
        $plugins = get_plugins('/' . $relative_dir);

        if (!$plugins) {
            return '';
        }

        $plugin_files = array_keys($plugins);
        sort($plugin_files);

        return $relative_dir . '/' . $plugin_files[0];
    }

    private function get_deployed_commit(string $local_path): array
    {
        $result = $this->run_git(['-C', $local_path, 'log', '-1', '--format=%H%n%s']);
        if ($result['code'] !== 0) {
            throw new RuntimeException($result['output']);
        }

        $lines = preg_split('/\r\n|\r|\n/', trim($result['output']), 2);

        return [
            'hash' => $lines[0] ?? '',
            'subject' => $lines[1] ?? '',
        ];
    }

    private function insert_project(string $repo_url, string $branch_name, string $local_path, string $plugin_file, array $commit, string $status, string $message): void
    {
        global $wpdb;

        $now = current_time('mysql');
        $wpdb->insert(
            self::table_name(),
            [
                'repo_url' => $repo_url,
                'repo_label' => $this->repo_label($repo_url),
                'branch_name' => $branch_name,
                'local_path' => $local_path,
                'plugin_file' => $plugin_file,
                'last_commit_hash' => $commit['hash'],
                'last_commit_subject' => $commit['subject'],
                'last_status' => $status,
                'last_message' => $message,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
        );
    }

    private function mark_project(int $project_id, string $status, string $message, ?string $plugin_file = null, bool $pulled = false, ?array $commit = null): void
    {
        global $wpdb;

        $data = [
            'last_status' => $status,
            'last_message' => wp_trim_words($message, 40, '...'),
            'updated_at' => current_time('mysql'),
        ];
        $format = ['%s', '%s', '%s'];

        if ($plugin_file !== null) {
            $data['plugin_file'] = $plugin_file;
            $format[] = '%s';
        }

        if ($commit !== null) {
            $data['last_commit_hash'] = $commit['hash'];
            $data['last_commit_subject'] = $commit['subject'];
            $format[] = '%s';
            $format[] = '%s';
        }

        if ($pulled) {
            $data['last_pulled_at'] = current_time('mysql');
            $format[] = '%s';
        }

        $wpdb->update(self::table_name(), $data, ['id' => $project_id], $format, ['%d']);
    }

    private function get_projects(): array
    {
        global $wpdb;

        return $wpdb->get_results("SELECT * FROM " . self::table_name() . " ORDER BY created_at DESC");
    }

    private function get_project(int $project_id): ?object
    {
        global $wpdb;

        return $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM " . self::table_name() . " WHERE id = %d", $project_id)
        );
    }

    private function build_local_path(string $repo_url): string
    {
        $path = wp_parse_url($repo_url, PHP_URL_PATH);
        $name = basename((string) $path, '.git');
        $slug = sanitize_title($name);

        if ($slug === '') {
            throw new RuntimeException(__('Could not determine a folder name from the repository URL.', 'git-puller'));
        }

        return trailingslashit(WP_PLUGIN_DIR) . $slug;
    }

    private function validate_repo_url(string $repo_url): void
    {
        $parts = wp_parse_url($repo_url);
        if (!$parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || empty($parts['user']) || empty($parts['pass'])) {
            throw new RuntimeException(__('Repository URL must use https://username:password@repo format.', 'git-puller'));
        }
    }

    private function validate_branch_name(string $branch_name): void
    {
        if ($branch_name === '' || !preg_match('/^[A-Za-z0-9._\/-]+$/', $branch_name) || strpos($branch_name, '..') !== false) {
            throw new RuntimeException(__('Branch name contains invalid characters.', 'git-puller'));
        }
    }

    private function repo_label(string $repo_url): string
    {
        $host = wp_parse_url($repo_url, PHP_URL_HOST) ?: '';
        $path = trim((string) wp_parse_url($repo_url, PHP_URL_PATH), '/');

        return trim($host . '/' . preg_replace('/\.git$/', '', $path), '/');
    }

    private function mask_repo_url(string $repo_url): string
    {
        $parts = wp_parse_url($repo_url);
        if (!$parts || empty($parts['host'])) {
            return $repo_url;
        }

        $scheme = $parts['scheme'] ?? 'https';
        $path = $parts['path'] ?? '';

        return $scheme . '://***:***@' . $parts['host'] . $path;
    }

    private function guard_action(string $nonce_action): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to do this.', 'git-puller'));
        }

        check_admin_referer($nonce_action);
    }

    private function redirect_with_notice(string $type, string $message): void
    {
        wp_safe_redirect(
            add_query_arg(
                [
                    'page' => 'git-puller',
                    'git_puller_notice_type' => $type,
                    'git_puller_notice' => rawurlencode($message),
                ],
                admin_url('tools.php')
            )
        );
        exit;
    }

    private function read_notice(): ?array
    {
        if (empty($_GET['git_puller_notice']) || empty($_GET['git_puller_notice_type'])) {
            return null;
        }

        $type = sanitize_key(wp_unslash($_GET['git_puller_notice_type']));
        if (!in_array($type, ['success', 'error', 'warning', 'info'], true)) {
            $type = 'info';
        }

        return [
            'type' => $type,
            'message' => sanitize_text_field(rawurldecode(wp_unslash($_GET['git_puller_notice']))),
        ];
    }

    private static function table_name(): string
    {
        global $wpdb;

        return $wpdb->prefix . self::TABLE;
    }
}
