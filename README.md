# IconCaptcha

👉 <u>中文</u> | [English](./README_EN.md)

这是一个轻量级、安全且易于集成的 PHP 图标点选验证码库。用户需要按照提示图标的顺序，在图片中依次点击对应的图标来完成验证。

✨ 特性

- 安全性：验证逻辑完全在服务端执行，坐标位置带有容错校验。
- 自定义：支持自定义验证码尺寸、图标数量、干扰项数量。
- 扩展性：支持自定义字体文件（实现 IconSetInterface）和背景图片文件夹。
- 高性能：惰性加载资源，低内存占用

## 📦 安装
```bash
composer require petalbranch/icon-captcha
```

## ⚠️ 安全警告 (Security Warning)

`icon_captcha_generate()` 函数返回的数组中包含 `answer` 字段（正确坐标数据）。

绝对禁止 将 `answer` 字段直接返回给前端（浏览器/客户端）。 
你 必须 将 `answer` 数据存储在服务端的 `Session`、`Redis` 或`数据库`中，验证时再取出比对。

## 快速开始

1. 生成验证码

在你的控制器（Controller）中生成验证码数据：

```php
<?php

use Illuminate\Support\Facades\Redis; // 示例使用 Laravel Redis Facade

// 1. 生成验证码
// 参数可选：icon_captcha_generate(宽度, 高度, 图标数, 干扰数);
$captcha = icon_captcha_generate(320, 200, 4, 2);

// 返回结构详解:
// [
//    'id'     => 'ic.65b7...', 
//    'image'  => 'base64_string...', // 主图 Base64 (不含前缀)
//    'mime'   => 'image/webp',       // 主图 MIME 类型 (image/webp 或 image/png)
//    'icons'  => ['base64...', ...], // 提示图标 Base64 数组 (固定为 PNG 格式)
//    'answer' => [[x,y,X,Y], ...]    // 正确答案坐标范围 (敏感数据! 勿传前端)
// ]

$id = $captcha['id'];
$answer = $captcha['answer'];

// 2.【关键】存储答案到服务端缓存（例如 Redis 或 Session）
// 建议设置较短的过期时间，例如 2-5 分钟
Redis::setex("captcha:{$id}", 300, json_encode($answer));

// 3. 返回给前端（排除 answer 字段）
$response = [
    'id'    => $id,
    'image' => $captcha['image'], // 前端: src="data:{{mime}};base64,{{image}}"
    'mime'  => $captcha['mime'],  // 这里会返回 image/webp 或 image/png
    'icons' => $captcha['icons']  // 前端: 遍历展示这组小图标提示用户点击
];

header('Content-Type: application/json');
echo json_encode($response);
```

2. 前端展示说明 (客户端)
前端在接收到数据后，需要注意 主图 和 提示图标 的 Base64 前缀处理方式不同。
- 主图 (`image`)：可能是 WebP 或 PNG，需根据返回的 mime 字段动态拼接。
- 提示图标 (`icons`)：为了保持透明背景，统一固定为 PNG 格式。

HTML/JS 拼接示例：
```javascript
// 假设 res 是后端返回的 JSON 数据
const data = res.data;

// 1. 渲染主验证码图片 (根据 mime 动态拼接)
// 格式: data:{mime};base64,{image}
const mainImgSrc = `data:${data.mime};base64,${data.image}`;
document.getElementById('captcha-image').src = mainImgSrc;

// 2. 渲染提示小图标 (固定为 image/png)
// 格式: data:image/png;base64,{icon}
data.icons.forEach(iconBase64 => {
const iconSrc = `data:image/png;base64,${iconBase64}`;
// ... 创建 img 标签并追加到 DOM ...
const img = document.createElement('img');
img.src = iconSrc;
document.getElementById('icon-container').appendChild(img);
});
```


3. 验证验证码 (服务端)

前端收集用户的点击坐标后，提交到验证接口：


  ```php
  <?php
  
  use Illuminate\Support\Facades\Redis;
  
  // 获取请求参数
  $params = $_POST; // 或 $request->all();
  
  $id = $params['id'] ?? '';
  $clickPositions = $params['click_positions'] ?? []; 
  // $clickPositions 格式示例: 
  // [[10, 20], [150, 60], ...] 
  // 或 
  // [['x'=>10, 'y'=>20], ['x'=>150, 'y'=>60], ...]
  
  if (empty($id) || empty($clickPositions)) {
      // 抛出错误...
  }
  
  // 1. 从缓存中取出正确答案
  $cacheKey = "captcha:{$id}";
  $cachedAnswer = Redis::get($cacheKey);
  
  if (!$cachedAnswer) {
      echo json_encode(['success' => false, 'message' => '验证码已过期或不存在']);
      exit;
  }
  
  $answer = json_decode($cachedAnswer, true);
  
  // 2. 执行验证
  // 注意参数顺序：(前端点击坐标, 正确答案坐标)
  $isVerified = icon_captcha_verify($clickPositions, $answer);
  
  if ($isVerified) {
      // ✅ 验证通过
      // 建议验证成功后立即删除缓存，防止重放攻击
      Redis::del($cacheKey);
      echo json_encode(['success' => true, 'message' => '验证通过']);
  } else {
      // ❌ 验证失败
      echo json_encode(['success' => false, 'message' => '验证失败，请重试']);
  }
  ```

## 🔐 安全增强：点击位置加密
为了防止恶意用户直接通过抓包工具篡改或伪造点击坐标，建议在前端对点击位置进行加密，后端解密后再传入 `icon_captcha_verify` 进行校验。

**流程：**
1. **前端**：用户点击 -> 获得坐标数组 -> JSON序列化 -> **加密** (AES/RSA) -> 发送密文。
2. **后端**：接收密文 -> **解密** -> 获得坐标数组 -> 调用 `icon_captcha_verify`。
> 具体代码示例请参考项目 examples 或根据自身业务逻辑实现。
---
- 前端加密 (示例)

假设你使用 crypto-js 或其他库对坐标数组进行 AES 加密：
```javascript
// 假设用户点击了两个位置
const rawPositions = [
    {x: 105, y: 33}, 
    {x: 210, y: 88}
];

// 将对象转为 JSON 字符串
const jsonStr = JSON.stringify(rawPositions);

// 使用你的加密逻辑 (例如 AES)
// 注意：密钥(Secret)应通过安全方式管理，或使用非对称加密
const encryptedPayload = MyEncryptionLib.encrypt(jsonStr, 'YOUR_SECRET_KEY');

// 发送给后端
$.post('/api/verify-captcha', {
    id: 'captcha_65b7...',
    ciphertext: encryptedPayload // 传输加密后的字符串
});
```

- 后端解密与校验

在 PHP 控制器中，先解密数据，还原成数组格式，再传给验证函数：

```php
<?php

// 1. 获取加密的 payload
$ciphertext = $_POST['ciphertext'];
$id = $_POST['id'];

// 2. 解密 (需与前端加密算法一致)
try {
    // 这里的 decrypt 是你项目中封装的解密方法
    $jsonStr = MySecurity::decrypt($ciphertext, 'YOUR_SECRET_KEY');
    
    // 解析为数组
    $clickPositions = json_decode($jsonStr, true);
    
    if (!is_array($clickPositions)) {
        throw new Exception("无效的数据格式");
    }
} catch (Exception $e) {
    // 解密失败，直接视为验证不通过
    die(json_encode(['success' => false, 'message' => '非法请求']));
}

// 3. 从缓存获取正确答案
$answer = json_decode(Redis::get("captcha:$id"), true);

// 4. 调用库函数进行验证 (传入解密后的原始坐标数组)
if (icon_captcha_verify($clickPositions, $answer)) {
    // 验证通过
} else {
    // 验证失败
}
```



## ⚙️ 参数配置
| **参数**                   | **类型**           | **默认值** | **说明**                           |
|--------------------------|------------------|---------|----------------------------------|
| `$width`                 | int              | 320     | 验证码图片宽度                          |
| `$height`                | int              | 200     | 验证码图片高度                          |
| `$length`                | int              | 4       | 需要用户点击的正确图标数量                    |
| `$decoyIconCount`        | int              | 2       | 干扰图标数量（不计入答案）                    |
| `$iconSet`               | IconSetInterface | null    | 自定义字体集实例                         |
| `$backgroundImageFolder` | string           | null    | 自定义背景图片文件夹路径                     |
| `$useWebp`               | bool             | true    | 优先使用 WebP 格式（体积更小），若不支持自动回退到 PNG |


## 🎨 高级用法

**自定义背景图**

默认情况下，库会生成随库自带的随机背景。如果你想使用随机背景图片：
```php
// 指定存放 jpg/png 图片的文件夹路径
$bgFolder = __DIR__ . '/assets/backgrounds';

$captcha = icon_captcha_generate(
    backgroundImageFolder: $bgFolder
);
```

**自定义图标字体**
默认的 IconSet 是配合内置的 FontAwesome 字体使用的。如果你想使用自己的 .ttf 字体文件，你需要创建一个新类来实现 IconSetInterface 接口，并定义该字体对应的 Unicode 映射表。
1. 创建自定义 IconSet 类
```php
<?php

use Petalbranch\IconCaptcha\Contract\IconSetInterface;

class MyCustomIconSet implements IconSetInterface
{
    protected string $fontFilePath;

    public function __construct(string $fontFilePath)
    {
        $this->fontFilePath = $fontFilePath;
    }

    /**
     * 定义你的字体图标映射
     * 键名：图标的别名（方便调试）
     * 键值：图标在 ttf 文件中的十六进制 Unicode 码
     */
    public function getIcons(): array
    {
        return [
            "star"   => "e001", 
            "heart"  => "e002",
            "camera" => "e003",
            // ... 更多图标
        ];
    }

    /**
     * 实现随机获取逻辑
     */
    public function getRandom(int $count = 4): array
    {
        $icons = $this->getIcons();
        $keys = array_rand($icons, $count);

        if (!is_array($keys)) $keys = [$keys];

        $result = [];
        foreach ($keys as $key) {
            $hex = $icons[$key];
            // 将十六进制转换为 UTF-8 字符
            $result[$key] = mb_chr(hexdec($hex), 'UTF-8');
        }
        return $result;
    }

    public function getFontPath(): string
    {
        return $this->fontFilePath;
    }
}
```
2. 在生成时使用
```php
// 1. 实例化你的自定义类
$myIconSet = new MyCustomIconSet('/path/to/your-custom-font.ttf');

// 2. 传入生成函数
$captcha = icon_captcha_generate(
    iconSet: $myIconSet
);
```

## 📄 许可证
本项目遵循 [Apache License 2.0](./LICENSE.txt) 开源协议。
