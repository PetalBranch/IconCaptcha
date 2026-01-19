<?php

namespace Petalbranch\IconCaptcha;

use Exception;
use GdImage;
use Petalbranch\IconCaptcha\Contract\IconSetInterface;
use Random\RandomException;
use RuntimeException;

/**
 * IconCaptcha 类用于生成基于图标的验证码。
 *
 * 该类允许用户自定义验证码的尺寸、图标数量、干扰图标数量等参数，并支持使用背景图片。
 * 生成的验证码包含一组随机图标，其中部分为正确答案，其余为干扰项。验证时需根据操作路径判断是否与预设的答案匹配。
 */
class IconCaptcha
{
    private string $fontPath;
    private array $backgroundImages = [];

    /**
     * @throws Exception
     */
    public function __construct(
        private readonly int      $width = 320,      // 图片宽度
        private readonly int      $height = 200,      // 图片高度
        private readonly int      $iconCount = 4,    // 生成几个图标
        private readonly int      $decoyIconCount = 2, //干扰图标数量
        private readonly int      $fontSizeMin = 16,    // 图标最小值
        private readonly int      $fontSizeMax = 32,    // 图标最大值
        private readonly int      $offsetAngle = 100,      // 允许±偏移角度 0 ~ 180度
        private ?IconSetInterface $iconSet = null,    // 允许外部传入字体路径
        private ?string           $backgroundImageFolder = null // 背景图文件夹支持jpg、png，随机取图
    )
    {
        $this->iconSet = $this->iconSet ?? new IconSet(dirname(__DIR__) . '/resources/fonts/fontawesomefree/fa-solid-900.ttf');
        $this->fontPath = $this->iconSet->getFontPath();
        $this->backgroundImageFolder = $this->backgroundImageFolder ?? dirname(__DIR__) . '/resources/background/';
        $this->loadBackgroundImages();
    }

    /**
     * @throws RandomException
     */
    public function generate(
        ?int $width = null,
        ?int $height = null,
        ?int $length = null,
        ?int $decoyIconCount = null
    ): array
    {
        $width = $width ?? $this->width;
        $height = $height ?? $this->height;
        $length = $length ?? $this->iconCount;
        $decoyIconCount = $decoyIconCount ?? $this->decoyIconCount;

        if (empty($this->backgroundImages)) {
            throw new RuntimeException('No background images found.');
        }

        $canvas = imagecreatetruecolor($width, $height);

        // --- 背景处理 (保持你的逻辑) ---
        $randomBgFile = $this->backgroundImages[array_rand($this->backgroundImages)];
        $bgImage = $this->loadImage($this->backgroundImageFolder . $randomBgFile);

        if ($bgImage) {
            imagefilter($bgImage, IMG_FILTER_GAUSSIAN_BLUR);
            $bgW = imagesx($bgImage);
            $bgH = imagesy($bgImage);

            // 智能裁剪/缩放
            if ($bgW < $width || $bgH < $height) {
                imagecopyresampled($canvas, $bgImage, 0, 0, 0, 0, $width, $height, $bgW, $bgH);
            } else {
                imagecopy($canvas, $bgImage, 0, 0, random_int(0, $bgW - $width), random_int(0, $bgH - $height), $width, $height);
            }
            imagedestroy($bgImage);
        } else {
            // 降级处理：如果没有背景图，填充随机浅色
            $bgColor = imagecolorallocate($canvas, random_int(200, 250), random_int(200, 250), random_int(200, 250));
            imagefill($canvas, 0, 0, $bgColor);
        }

        // --- 生成图标 ---
        $totalIcons = $length + $decoyIconCount;
        $iconsData = $this->iconSet->getRandom($totalIcons);

        $iconPositions = []; // 存储坐标用于验证
        $iconBase64 = [];    // 存储前端显示的提示图标

        $i = 0;
        foreach ($iconsData as $char) {
            // 在画布上绘制图标
            $position = $this->imageChar($canvas, $width, $height, $char, $iconPositions);

            // 如果是前 N 个（正确答案），记录位置并生成提示图
            if ($i < $length) {
                $iconPositions[$i] = $position;
                $iconBase64[] = $this->baseImageChar($char);
            } else {
                // 干扰图标，只记录占位，防止重叠，但不记录为答案
                $iconPositions['decoy_' . $i] = $position;
            }
            $i++;
        }

        // 添加噪点
        $this->addNoise($canvas, imagecolorallocate($canvas, 200, 200, 200));

        // 输出
        ob_start();
        imagepng($canvas);
        $imageData = ob_get_clean();
        imagedestroy($canvas);

        return [
            'id' => uniqid("captcha_", true),
            'image' => base64_encode($imageData),
            'icons' => $iconBase64,
            // 注意：answer 只包含前 $length 个正确图标的坐标
            'answer' => array_slice($iconPositions, 0, $length),
        ];
    }

    /**
     * 验证操作路径是否与答案匹配。
     *
     * @param array $clickPositions 操作路径数组，每个元素是一个表示点击位置
     * @param array $answer 答案数组，每个元素是一个矩形范围[minX, minY, maxX, maxY]，表示允许的坐标范围
     * @return bool 如果所有操作路径的终点都在对应的答案矩形范围内，则返回true；否则返回false
     */
    public function verify(array $clickPositions, array $answer): bool
    {
        // 简单的数量校验
        if (count($clickPositions) !== count($answer)) {
            return false;
        }

        $i = 0;
        foreach ($clickPositions as $xy) {
            if (count($xy) !== 2) return false;
            [$x, $y] = $xy;
            $box = $answer[$i] ?? null;
            if (!$box) return false;

            // 检查坐标是否在矩形范围内
            // box格式: [minX, minY, maxX, maxY]
            if ($x < $box[0] || $x > $box[2] || $y < $box[1] || $y > $box[3]) {
                return false;
            }

            $i++;
        }

        return true;
    }

    /**
     * 从指定目录加载背景图像，并将它们的文件名填充到内部列表中。
     *
     * @return void
     * @throws RuntimeException 如果背景图像目录不存在
     */
    private function loadBackgroundImages(): void
    {
        if (!is_dir($this->backgroundImageFolder)) return;

        $folder = rtrim($this->backgroundImageFolder, '/\\') . DIRECTORY_SEPARATOR;
        foreach (['jpg', 'jpeg', 'png'] as $ext) {
            $files = glob($folder . "*." . $ext);
            if ($files) {
                foreach ($files as $f) $this->backgroundImages[] = basename($f);
            }
        }
        $this->backgroundImages = array_unique($this->backgroundImages);
    }


    /**
     * 从给定的文件路径加载图像
     *
     * @param string $path 图像的文件路径。支持的格式为“jpg”、“jpeg”和“png”。
     *
     * @return resource|false 成功时返回图像资源标识符，如果不支持图像类型或无法加载文件，则返回false
     */
    private function loadImage(string $path)
    {
        if (!file_exists($path)) return false;
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return match ($ext) {
            'jpg', 'jpeg' => @imagecreatefromjpeg($path),
            'png' => @imagecreatefrompng($path),
            default => false,
        };
    }


    /**
     * 检查指定的轴对齐边界框（AABB）是否与现有位置中的任何一个重叠。
     *
     * @param int $minX 当前检查的AABB的最小X坐标
     * @param int $minY 当前检查的AABB的最小Y坐标
     * @param int $maxX 当前检查的AABB的最大X坐标
     * @param int $maxY 当前检查的AABB的最大Y坐标
     * @param array $existingPositions 一个数组，每个元素为一个四元组 [minX, minY, maxX, maxY] 表示已存在的AABB的位置
     * @return bool 如果当前AABB与$existingPositions中任何一个AABB重叠，则返回true；否则返回false
     */
    private function isOverlapAABB(int $minX, int $minY, int $maxX, int $maxY, array $existingPositions): bool
    {
        foreach ($existingPositions as $pos) {
            // $pos 格式: [minX, minY, maxX, maxY]
            // 如果 两个矩形没有重叠，则返回 false
            // 逻辑：如果不(A在B左边 或 A在B右边 或 A在B上面 或 A在B下面)
            $noOverlap = (
                $maxX < $pos[0] || // 当前在已知左边
                $minX > $pos[2] || // 当前在已知右边
                $maxY < $pos[1] || // 当前在已知上面
                $minY > $pos[3]    // 当前在已知下面
            );

            if (!$noOverlap) return true; // 有重叠
        }
        return false;
    }


    /**
     * 在给定的画布上绘制一个字符，并返回其包围盒。
     *
     * @param GdImage $canvas 用于绘制字符的GD图像资源
     * @param int $width 画布宽度
     * @param int $height 画布高度
     * @param string $char 要绘制的字符
     * @param array $iconPositions 已有图标的坐标位置，用于防止重叠
     * @return array 返回字符的包围盒坐标 [minX, minY, maxX, maxY]
     * @throws RandomException
     */
    private function imageChar(GdImage $canvas, int $width, int $height, string $char, array $iconPositions): array
    {
        $size = random_int($this->fontSizeMin, $this->fontSizeMax);
        $angle = random_int(-$this->offsetAngle, $this->offsetAngle);

        // 随机颜色 (深色系，保证在浅色背景上可见)
        $r = random_int(20, 100);
        $g = random_int(20, 100);
        $b = random_int(20, 100);
        $color = imagecolorallocate($canvas, $r, $g, $b);
        $shadowColor = imagecolorallocate($canvas, $r + 80, $g + 80, $b + 80);

        // --- 坐标碰撞检测 (增加最大尝试次数防止死循环) ---
        $attempts = 0;
        $maxAttempts = 50;

        do {
            $valid = true;
            $margin = 10; // 边距

            // 随机生成坐标
            // 注意：imagettftext 的 x,y 是字体的基线起点（大致是左下角，但也受旋转影响）
            $x = random_int($margin, $width - $size - $margin);
            $y = random_int($size + $margin, $height - $margin);

            // 预计算边界框来检测碰撞
            $bbox = imagettfbbox($size, $angle, $this->fontPath, $char);

            // 修正 bbox 坐标到绝对坐标
            $x0 = $bbox[0] + $x;
            $y0 = $bbox[1] + $y;
            $x1 = $bbox[2] + $x;
            $y1 = $bbox[3] + $y;
            $x2 = $bbox[4] + $x;
            $y2 = $bbox[5] + $y;
            $x3 = $bbox[6] + $x;
            $y3 = $bbox[7] + $y;

            // 获取绝对 AABB 包围盒 (Axis Aligned Bounding Box)
            $minX = min($x0, $x1, $x2, $x3) - 2;
            $minY = min($y0, $y1, $y2, $y3) - 2;
            $maxX = max($x0, $x1, $x2, $x3) + 2;
            $maxY = max($y0, $y1, $y2, $y3) + 2;

            // 检测重叠
            if ($this->isOverlapAABB($minX, $minY, $maxX, $maxY, $iconPositions)) {
                $valid = false;
            }

            $attempts++;
        } while (!$valid && $attempts < $maxAttempts);

        // --- 绘制 ---
        imagettftext($canvas, $size, $angle, $x + 2, $y + 2, $shadowColor, $this->fontPath, $char);
        imagettftext($canvas, $size, $angle, $x, $y, $color, $this->fontPath, $char);

        // 返回最终的包围盒用于验证
        return [$minX, $minY, $maxX, $maxY];
    }


    /**
     * 生成包含指定字符的图像，并返回该图像的base64编码字符串。
     *
     * @param string $char 要绘制在图像上的字符
     * @return string 返回包含字符图像的base64编码字符串
     */
    private function baseImageChar(string $char): string
    {
        $wh = 40; // 稍微大一点，容纳不同形状的图标
        $canvas = imagecreatetruecolor($wh, $wh);
        imagesavealpha($canvas, true);
        imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));

        $size = 20;
        $color = imagecolorallocate($canvas, 60, 60, 60);

        // --- 自动居中计算 ---
        $bbox = imagettfbbox($size, 0, $this->fontPath, $char);
        // 文字宽度 = 右下X - 左下X
        $textW = $bbox[2] - $bbox[0];
        // 文字高度 = 左下Y - 左上Y
        $textH = $bbox[1] - $bbox[7];

        // 计算居中坐标
        $x = (int)(($wh - $textW) / 2) - $bbox[0]; // 修正水平偏移
        $y = (int)(($wh - $textH) / 2) + $textH - $bbox[1]; // 修正垂直偏移 (基线问题)

        imagettftext($canvas, $size, 0, $x, $y, $color, $this->fontPath, $char);

        ob_start();
        imagepng($canvas);
        $data = ob_get_clean();
        imagedestroy($canvas);

        return base64_encode($data);
    }

    /**
     * 向图像添加噪声，包括随机圆点和干扰线。
     *
     * @param GdImage $image 要添加噪声的图像资源
     * @param int $color 噪声的颜色
     * @return void
     * @throws RandomException
     */
    private function addNoise(GdImage $image, int $color): void
    {
        // 简单的随机圆点
        for ($i = 0; $i < 50; $i++) {
            imagefilledellipse(
                $image,
                random_int(0, $this->width),
                random_int(0, $this->height),
                3, 3, $color
            );
        }
        // 简单的干扰线
        for ($i = 0; $i < 3; $i++) {
            imageline(
                $image,
                random_int(0, $this->width),
                random_int(0, $this->height),
                random_int(0, $this->width),
                random_int(0, $this->height),
                $color
            );
        }
    }
}