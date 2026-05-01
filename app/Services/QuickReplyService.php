<?php

namespace App\Services;

class QuickReplyService
{
    /**
     * Social/greeting patterns — whole-message match only.
     * Returns a quick reply string, or null if this is NOT a social message.
     */
    private const SOCIAL_PATTERNS = [
        '/^(干的漂亮|做的好|太棒了|不错|很好|好的|可以|行|OK|ok|收到|了解|明白|知道了|嗯|嗯嗯|好|谢谢|谢谢你|多谢|感谢|辛苦了|辛苦|厉害|牛|棒|赞|666|👍|❤️|😊|🎉|哈哈|哈哈哈|呵呵|嘻嘻)[。！!~？.\s]*$/u',
        '/^(你好|hi|hello|嗨|早|早上好|上午好|下午好|晚上好|晚安|拜拜|再见|byebye|bye)[。！!~？.\s]*$/ui',
        '/^(没事了|就这样|先这样|OK了|好了|完事了|可以了|就这些|没别的了|没有了|算了|好吧)[。！!~？.\s]*$/u',
    ];

    private const TASK_KEYWORDS = [
        '帮我', '帮忙', '创建', '建一个', '发一下', '搜索', '搜一下', '查一下', '查找',
        '读一下', '分析', '提炼', '总结', '发送', '安排', '修改', '删除',
        '加上', '设置', '打开', '看一下', '看看', '告诉我', '写一个', '做一个',
        '拉一个', '加一下', '发个', '建个', '改一下', '找一下',
        'http', 'https', '日程', '会议', '邮件', '文档', '表格', '日历',
    ];

    /**
     * Try to produce an instant reply for obvious social messages.
     * Returns reply string on match, null otherwise (caller proceeds to Run pipeline).
     */
    public function attempt(string $text): ?string
    {
        $text = trim($text);

        // Skip empty, too long, or messages with URLs
        if ($text === '' || mb_strlen($text) > 30) {
            return null;
        }

        // Reject if contains ANY task keyword
        foreach (self::TASK_KEYWORDS as $kw) {
            if (mb_stripos($text, $kw) !== false) {
                return null;
            }
        }

        // Match social patterns (whole-message regex)
        foreach (self::SOCIAL_PATTERNS as $pattern) {
            if (preg_match($pattern, $text)) {
                return $this->respond($text);
            }
        }

        return null;
    }

    private function respond(string $text): string
    {
        if (preg_match('/谢|感谢|辛苦|多谢/u', $text)) {
            return collect(['不客气～', '随时为你服务！', '不用谢😊'])->random();
        }
        if (preg_match('/漂亮|棒|不错|厉害|牛|赞|太棒|666|👍|做的好/u', $text)) {
            return collect(['谢谢夸奖😊', '过奖啦～有需要随时叫我！', '嘿嘿，能帮到你就好！'])->random();
        }
        if (preg_match('/你好|hi|hello|嗨|早|上午好|下午好|晚上好/ui', $text)) {
            return '你好～有什么需要帮忙的吗？';
        }
        if (preg_match('/晚安/u', $text)) {
            return '晚安～明天见！';
        }
        if (preg_match('/拜拜|再见|bye/ui', $text)) {
            return '再见👋有事随时找我！';
        }
        if (preg_match('/哈哈|呵呵|嘻嘻/u', $text)) {
            return '😄';
        }
        // Default for ack-type messages: 好的, 收到, 嗯, 了解, etc.
        return collect(['好的，有需要随时叫我～', '收到！', '好的👌'])->random();
    }
}
