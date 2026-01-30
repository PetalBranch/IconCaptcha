<?php

use Petalbranch\IconCaptcha\Contract\IconSetInterface;
use Petalbranch\IconCaptcha\IconCaptcha;

if (!function_exists('icon_captcha_generate')) {
    /**
     * 生成验证码<br>
     * ⚠️ 注意：返回结果包含 answer，由调用方负责存储到 Session/Cache，切勿直接返回给前端。
     *
     * @param int|null $width 验证码宽度 默认 320
     * @param int|null $height 验证码高度 默认 200
     * @param int|null $length 生成图标数量 默认 4
     * @param int|null $decoyIconCount 额外的图标数量 默认 2
     * @return array 返回验证码ID、图片base64、图标组base64、以及【需要服务端存储的答案】
     * @throws Exception
     */
    function icon_captcha_generate(
        ?int              $width = null,
        ?int              $height = null,
        ?int              $length = null,
        ?int              $decoyIconCount = null,
        ?IconSetInterface $iconSet = null,
        ?string           $backgroundImageFolder = null,
        bool              $useWebp = true
    ): array
    {
        $ic = new IconCaptcha(iconSet: $iconSet, backgroundImageFolder: $backgroundImageFolder);
        return $ic->generate($width, $height, $length, $decoyIconCount, $useWebp);

    }
}


if (!function_exists("icon_captcha_verify")) {
    /**
     * 验证操作路径是否与答案匹配。
     *
     * @param array $clickPositions 操作路径数组，每个元素是一个表示点击位置
     * @param array $answer 答案数组，每个元素是一个矩形范围[minX, minY, maxX, maxY]，表示允许的坐标范围
     * @return bool 如果所有操作路径的终点都在对应的答案矩形范围内，则返回true；否则返回false
     */
    function icon_captcha_verify(array $clickPositions, array $answer): bool
    {
        $ic = new IconCaptcha();
        return $ic->verify($clickPositions, $answer);
    }
}