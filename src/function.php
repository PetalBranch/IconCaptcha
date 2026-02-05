<?php

use Petalbranch\IconCaptcha\Contract\IconSetInterface;
use Petalbranch\IconCaptcha\IconCaptcha;

if (!function_exists('icon_captcha_generate')) {
    /**
     * 生成验证码 <br>
     * Generate verification code <br>
     * ⚠️ 注意：返回结果包含 answer，由调用方负责存储到 Session/Cache，切勿直接返回给前端。<br>
     * ⚠️ Note: The result contains the answer. The caller is responsible for storing it in the Session/Cache. Never return it directly to the frontend.
     *
     * @param int|null $width 验证码宽度 默认 320; Width of the verification code. Default is 320;
     * @param int|null $height 验证码高度 默认 200; Height of the verification code. Default is 200;
     * @param int|null $length 生成图标数量 默认 4; Number of target icons to generate. Default is 4;
     * @param int|null $decoyIconCount 额外的图标数量 默认 2; Number of decoy (distraction) icons. Default is 2;
     * @return array 返回验证码ID、图片base64、图标组base64、以及【需要服务端存储的答案】; Returns the CAPTCHA ID, image Base64, icons group Base64, and the [answer that needs to be stored on the server];
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
     * 验证操作路径是否与答案匹配<br>
     * Verify if the operation path matches the answer.
     *
     * @param array $clickPositions 操作路径数组，每个元素是一个表示点击位置; Array of operation paths, where each element represents a click position;
     * @param array $answer 答案数组，每个元素是一个矩形范围[minX, minY, maxX, maxY]，表示允许的坐标范围; Array of answers, where each element is a rectangular range [minX, minY, maxX, maxY] indicating the allowed coordinate range;
     * @return bool 如果所有操作路径的终点都在对应的答案矩形范围内，则返回true；否则返回false; Returns true if the endpoint of every operation path falls within the corresponding answer rectangle; otherwise returns false;
     */
    function icon_captcha_verify(array $clickPositions, array $answer): bool
    {
        $ic = new IconCaptcha();
        return $ic->verify($clickPositions, $answer);
    }
}