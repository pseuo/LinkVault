<?php

declare(strict_types=1);

final class WorkflowController
{
    public static function dispatch(string $method, string $path, PDO $pdo, array $config, LinkService $service): void
    {
        if ($method !== 'POST') {
            return;
        }
        if ($path === '/presets/save') {
            require_login();
            require_csrf();
            $name = trim((string)($_POST['name'] ?? ''));
            [$tagsValid, $tags] = normalize_tags(trim((string)($_POST['tags'] ?? '')));
            $days = max(0, min(3650, (int)($_POST['expires_days'] ?? 0)));
            $maxClicks = trim((string)($_POST['max_clicks'] ?? ''));
            $fallbackUrl = trim((string)($_POST['fallback_url'] ?? ''));
            $invalidMessage = trim((string)($_POST['invalid_message'] ?? ''));
            $campaign = [];
            foreach (['campaign_name', 'campaign_source', 'campaign_medium', 'campaign_content'] as $field) {
                $campaign[$field] = trim((string)($_POST[$field] ?? ''));
            }
            $valid = $name !== '' && text_length($name) <= 60
                && preg_match('/[\x00-\x1F\x7F]/u', $name) !== 1
                && $tagsValid
                && ($maxClicks === '' || (ctype_digit($maxClicks) && (int)$maxClicks >= 1 && (int)$maxClicks <= 2147483647))
                && ($fallbackUrl === '' || valid_target_url($fallbackUrl, max(1, (int)($config['target_url_max_length'] ?? 2048))))
                && valid_invalid_message($invalidMessage);
            foreach ($campaign as $value) {
                $valid = $valid && valid_campaign_value($value);
            }
            if (!$valid) {
                flash('预设名称或字段无效，请检查后重试。', 'error');
                redirect_to(app_path('/'));
            }
            $values = array_merge($campaign, [
                'short_domain_id' => max(0, (int)($_POST['short_domain_id'] ?? 0)),
                'tags' => format_tags_input($tags),
                'expires_days' => $days,
                'max_clicks' => $maxClicks,
                'is_one_time' => (string)($_POST['is_one_time'] ?? '') === '1',
                'one_time_mode' => (string)($_POST['one_time_mode'] ?? '') === 'confirm' ? 'confirm' : 'immediate',
                'fallback_url' => $fallbackUrl,
                'invalid_message' => $invalidMessage,
            ]);
            $id = $service->saveLinkPreset($name, $values);
            audit_event($pdo, $config, 'admin', 'save_link_preset', 'success', 'link_preset', (string)$id, ['name' => $name]);
            flash('创建预设已保存。');
            redirect_to(app_path('/'));
        }
        if ($path === '/presets/delete') {
            require_login();
            require_csrf();
            $id = positive_integer_id($_POST['id'] ?? null);
            $deleted = $id > 0 && $service->deleteLinkPreset($id);
            audit_event($pdo, $config, 'admin', 'delete_link_preset', $deleted ? 'success' : 'failure', 'link_preset', (string)$id);
            flash($deleted ? '创建预设已删除。' : '创建预设不存在。', $deleted ? 'ok' : 'error');
            redirect_to(app_path('/'));
        }
        if ($path === '/webhooks/replay') {
            require_login();
            require_csrf();
            $eventId = strtolower(trim((string)($_POST['event_id'] ?? '')));
            $replayed = (new LifecycleWebhookService($pdo, $config))->replayDead($eventId);
            audit_event($pdo, $config, 'admin', 'webhook_replay', $replayed ? 'success' : 'failure', 'webhook_event', $eventId);
            flash($replayed ? '死信已重新加入投递队列。' : '事件不存在或当前不是死信。', $replayed ? 'ok' : 'error');
            redirect_to(list_path('', 'active', 1, 'all', 'created_desc', '', false, 0, 'webhooks'));
        }
        if (in_array($path, ['/notifications/read', '/notifications/dismiss'], true)) {
            require_login();
            require_csrf();
            $id = positive_integer_id($_POST['id'] ?? null);
            $notifications = new AdminNotificationService($pdo);
            $updated = $id > 0 && ($path === '/notifications/read' ? $notifications->markRead($id) : $notifications->dismiss($id));
            audit_event($pdo, $config, 'admin', $path === '/notifications/read' ? 'notification_read' : 'notification_dismiss', $updated ? 'success' : 'failure', 'admin_notification', (string)$id);
            redirect_to(app_path('/?section=notifications'));
        }
        $p2 = new P2Service($pdo, $config, $service);
        if ($path === '/workflows/tag-rules/save') {
            require_login();
            require_csrf();
            [, $tags] = normalize_tags((string)($_POST['tags'] ?? ''));
            try {
                $id = $p2->saveTagRule(
                    trim((string)($_POST['name'] ?? '')),
                    (string)($_POST['field'] ?? ''),
                    (string)($_POST['operator'] ?? ''),
                    trim((string)($_POST['pattern'] ?? '')),
                    $tags,
                    (int)($_POST['priority'] ?? 100),
                    (string)($_POST['is_enabled'] ?? '') === '1',
                    positive_integer_id($_POST['id'] ?? null) ?: null
                );
                audit_event($pdo, $config, 'admin', 'tag_rule_save', 'success', 'tag_rule', (string)$id);
                flash('批量标签规则已保存。');
            } catch (InvalidArgumentException|PDOException) {
                flash('标签规则无效或名称已存在。', 'error');
            }
            redirect_to(app_path('/?section=workflows'));
        }
        if ($path === '/workflows/tag-rules/delete') {
            require_login();
            require_csrf();
            $id = positive_integer_id($_POST['id'] ?? null);
            $deleted = $id > 0 && $p2->deleteTagRule($id);
            audit_event($pdo, $config, 'admin', 'tag_rule_delete', $deleted ? 'success' : 'failure', 'tag_rule', (string)$id);
            flash($deleted ? '标签规则已删除。' : '标签规则不存在。', $deleted ? 'ok' : 'error');
            redirect_to(app_path('/?section=workflows'));
        }
        if ($path === '/workflows/tag-rules/apply') {
            require_login();
            require_csrf();
            $ids = is_array($_POST['selected'] ?? null) ? $_POST['selected'] : [];
            try {
                $result = $p2->applyTagRules($ids);
                audit_event($pdo, $config, 'admin', 'tag_rules_apply', 'success', 'link', null, $result);
                flash('规则匹配 ' . $result['matched'] . ' 条，更新 ' . $result['changed'] . ' 条。');
            } catch (InvalidArgumentException) {
                flash('规则应用范围无效。', 'error');
            }
            redirect_to(app_path('/?section=workflows'));
        }
        if ($path === '/workflows/duplicates/merge') {
            require_login();
            require_csrf();
            $duplicates = is_array($_POST['duplicate_ids'] ?? null) ? $_POST['duplicate_ids'] : [];
            try {
                $result = $p2->mergeDuplicates((int)($_POST['canonical_id'] ?? 0), $duplicates);
                audit_event($pdo, $config, 'admin', 'duplicates_merge', 'success', 'link', (string)($_POST['canonical_id'] ?? ''), $result);
                flash('已合并 ' . $result['merged'] . ' 条，跳过 ' . $result['skipped'] . ' 条。');
            } catch (InvalidArgumentException|PDOException) {
                flash('重复链接合并失败，请确认所选链接目标地址和域名一致。', 'error');
            }
            redirect_to(app_path('/?section=workflows'));
        }
        if ($path === '/marketing/funnels/save') {
            require_login();
            require_csrf();
            $stages = array_values(array_filter(array_map('trim', explode(',', (string)($_POST['stages'] ?? '')))));
            try {
                $id = $p2->saveFunnel(trim((string)($_POST['name'] ?? '')), $stages);
                audit_event($pdo, $config, 'admin', 'funnel_save', 'success', 'funnel', (string)$id);
                flash('漏斗已保存。');
            } catch (InvalidArgumentException|PDOException) {
                flash('漏斗名称或事件阶段无效。', 'error');
            }
            redirect_to(app_path('/?section=marketing'));
        }
        if ($path === '/trust/blacklist/save') {
            require_login();
            require_csrf();
            try {
                $id = $p2->addBlacklistDomain(
                    (string)($_POST['hostname'] ?? ''),
                    (string)($_POST['reason'] ?? ''),
                    (string)($_POST['include_subdomains'] ?? '') === '1'
                );
                audit_event($pdo, $config, 'admin', 'domain_blacklist_save', 'success', 'domain_blacklist', (string)$id);
                flash('域名黑名单已更新。');
            } catch (InvalidArgumentException|PDOException) {
                flash('黑名单域名或原因无效。', 'error');
            }
            redirect_to(app_path('/?section=trust'));
        }
        if ($path === '/trust/scan') {
            require_login();
            require_csrf();
            try {
                $risk = $p2->scanLink((int)($_POST['link_id'] ?? 0));
                audit_event($pdo, $config, 'admin', 'risk_scan', 'success', 'link', (string)($_POST['link_id'] ?? ''), $risk);
                flash('风险扫描完成：' . $risk['risk_level'] . '，评分 ' . $risk['score'] . '。');
            } catch (InvalidArgumentException) {
                flash('待扫描链接不存在。', 'error');
            }
            redirect_to(app_path('/?section=trust'));
        }
        if ($path === '/trust/reports/action') {
            require_login();
            require_csrf();
            try {
                $updated = $p2->processReport(
                    (int)($_POST['report_id'] ?? 0),
                    (string)($_POST['report_action'] ?? ''),
                    (string)($_POST['note'] ?? '')
                );
                flash($updated ? '举报处置已记录。' : '举报不存在。', $updated ? 'ok' : 'error');
            } catch (InvalidArgumentException) {
                flash('举报处置参数无效。', 'error');
            }
            redirect_to(app_path('/?section=trust'));
        }
    }
}
