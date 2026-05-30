<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;
use Typecho\Widget;

class FriendLinksLight_Action extends Widget
{
    public function action()
    {
        $user = $this->widget('Widget_User');
        if (!$user->pass('administrator', true)) {
            $this->response->throwJson(['success' => false, 'message' => _t('权限不足')]);
        }
        $updated = FriendLinksLight_Plugin::checkAllLinksStatus();
        $this->response->throwJson([
            'success' => true,
            'message' => sprintf(_t('已检查 %d 个链接的状态'), $updated),
            'updated' => $updated
        ]);
    }

    public function cron()
    {
        $key = $this->request->get('key');
        $options = \Utils\Helper::options()->plugin('FriendLinksLight');
        $secretKey = $options->secretKey ?? '';
        if (!empty($secretKey) && $key !== $secretKey) {
            $this->response->setStatus(403);
            echo 'Invalid key';
            return;
        }
        $updated = FriendLinksLight_Plugin::checkAllLinksStatus();
        echo sprintf("OK: Checked %d links at %s", $updated, date('Y-m-d H:i:s'));
    }
}