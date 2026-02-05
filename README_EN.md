# IconCaptcha

[中文](./README.md) | 👉 <u>English</u>

A lightweight, secure, and easy-to-integrate PHP icon-click CAPTCHA library. Users must click the corresponding icons on the image in the order indicated by the prompt icons to complete verification.

✨ **Features**

- **Security**: Verification logic runs entirely on the server side, with tolerance-based coordinate validation.
- **Customizable**: Supports customizing CAPTCHA dimensions, number of target icons, and number of decoy icons.
- **Extensible**: Allows custom font files (by implementing `IconSetInterface`) and background image folders.
- **High Performance**: Lazy-loads resources with low memory consumption.

## 📦 Installation
```bash
composer require petalbranch/icon-captcha
```

## ⚠️ Security Warning

The array returned by the `icon_captcha_generate()` function includes an `answer` field containing the correct coordinate data.

**Never** send the `answer` field directly to the frontend (browser/client).  
You **must** store the `answer` data securely on the server—using `Session`, `Redis`, or a database—and retrieve it only during verification.

## Quick Start

### 1. Generate CAPTCHA

In your controller, generate CAPTCHA data:

```php
<?php

use Illuminate\Support\Facades\Redis; // Example using Laravel Redis Facade

// 1. Generate CAPTCHA
// Optional parameters: icon_captcha_generate(width, height, icon_count, decoy_count);
$captcha = icon_captcha_generate(320, 200, 4, 2);

// Return structure details:
// [
//    'id'     => 'ic.65b7...', 
//    'image'  => 'base64_string...', // Main image Base64 (without prefix)
//    'mime'   => 'image/webp',       // Main image MIME type (image/webp or image/png)
//    'icons'  => ['base64...', ...], // Prompt icons as Base64 array (always PNG)
//    'answer' => [[x,y,X,Y], ...]    // Correct answer coordinate ranges (SENSITIVE! DO NOT SEND TO FRONTEND)
// ]

$id = $captcha['id'];
$answer = $captcha['answer'];

// 2. [CRITICAL] Store the answer securely on the server (e.g., Redis or Session)
// Set a short expiration time (e.g., 2–5 minutes)
Redis::setex("captcha:{$id}", 300, json_encode($answer));

// 3. Return response to frontend (exclude the 'answer' field)
$response = [
    'id'    => $id,
    'image' => $captcha['image'], // Frontend usage: src="data:{{mime}};base64,{{image}}"
    'mime'  => $captcha['mime'],  // Returns either image/webp or image/png
    'icons' => $captcha['icons']  // Frontend: render these small icons as click prompts
];

header('Content-Type: application/json');
echo json_encode($response);
```

### 2. Frontend Display Instructions (Client Side)

After receiving the data, note that the Base64 prefixes for the main image and prompt icons differ:

- **Main image (`image`)**: May be WebP or PNG—prepend dynamically based on the `mime` field.
- **Prompt icons (`icons`)**: Always PNG to preserve transparency.

**HTML/JS Example**:
```javascript
// Assume `res` is the JSON response from the backend
const data = res.data;

// 1. Render main CAPTCHA image (dynamically prepend MIME type)
// Format: data:{mime};base64,{image}
const mainImgSrc = `data:${data.mime};base64,${data.image}`;
document.getElementById('captcha-image').src = mainImgSrc;

// 2. Render prompt icons (always image/png)
// Format: data:image/png;base64,{icon}
data.icons.forEach(iconBase64 => {
    const iconSrc = `data:image/png;base64,${iconBase64}`;
    // ... create img element and append to DOM ...
    const img = document.createElement('img');
    img.src = iconSrc;
    document.getElementById('icon-container').appendChild(img);
});
```

### 3. Verify CAPTCHA (Server Side)

After the frontend collects user click coordinates, submit them to a verification endpoint:

```php
<?php

use Illuminate\Support\Facades\Redis;

// Get request parameters
$params = $_POST; // or $request->all();

$id = $params['id'] ?? '';
$clickPositions = $params['click_positions'] ?? []; 
// Example formats for $clickPositions:
// [[10, 20], [150, 60], ...]
// OR
// [['x'=>10, 'y'=>20], ['x'=>150, 'y'=>60], ...]

if (empty($id) || empty($clickPositions)) {
    // Handle error...
}

// 1. Retrieve correct answer from cache
$cacheKey = "captcha:{$id}";
$cachedAnswer = Redis::get($cacheKey);

if (!$cachedAnswer) {
    echo json_encode(['success' => false, 'message' => 'CAPTCHA expired or not found']);
    exit;
}

$answer = json_decode($cachedAnswer, true);

// 2. Perform verification
// Note parameter order: (user clicks, correct answer)
$isVerified = icon_captcha_verify($clickPositions, $answer);

if ($isVerified) {
    // ✅ Verification successful
    // Recommended: delete cache immediately to prevent replay attacks
    Redis::del($cacheKey);
    echo json_encode(['success' => true, 'message' => 'Verification passed']);
} else {
    // ❌ Verification failed
    echo json_encode(['success' => false, 'message' => 'Verification failed. Please try again']);
}
```

## 🔐 Security Enhancement: Encrypt Click Coordinates

To prevent attackers from tampering with or forging click coordinates via packet sniffing, we recommend encrypting coordinates on the frontend and decrypting them on the backend before calling `icon_captcha_verify`.

**Workflow**:
1. **Frontend**: User clicks → get coordinates → serialize to JSON → **encrypt** (e.g., AES/RSA) → send ciphertext.
2. **Backend**: Receive ciphertext → **decrypt** → obtain coordinate array → call `icon_captcha_verify`.

> See project `examples/` for implementation details or adapt to your business logic.

**Frontend Encryption Example** (using a library like `crypto-js`):
```javascript
// Example user clicks at two positions
const rawPositions = [
    {x: 105, y: 33}, 
    {x: 210, y: 88}
];

// Serialize to JSON string
const jsonStr = JSON.stringify(rawPositions);

// Encrypt using your chosen method (e.g., AES)
// Note: Manage secret keys securely, or use asymmetric encryption
const encryptedPayload = MyEncryptionLib.encrypt(jsonStr, 'YOUR_SECRET_KEY');

// Send to backend
$.post('/api/verify-captcha', {
    id: 'captcha_65b7...',
    ciphertext: encryptedPayload // Send encrypted string
});
```

**Backend Decryption & Verification**:
```php
<?php

// 1. Get encrypted payload
$ciphertext = $_POST['ciphertext'];
$id = $_POST['id'];

// 2. Decrypt (must match frontend algorithm)
try {
    // Assume `decrypt` is your project's decryption method
    $jsonStr = MySecurity::decrypt($ciphertext, 'YOUR_SECRET_KEY');
    
    // Parse into array
    $clickPositions = json_decode($jsonStr, true);
    
    if (!is_array($clickPositions)) {
        throw new Exception("Invalid data format");
    }
} catch (Exception $e) {
    // Decryption failed → treat as invalid request
    die(json_encode(['success' => false, 'message' => 'Invalid request']));
}

// 3. Fetch correct answer from cache
$answer = json_decode(Redis::get("captcha:$id"), true);

// 4. Verify using decrypted coordinates
if (icon_captcha_verify($clickPositions, $answer)) {
    // Success
} else {
    // Failure
}
```

## ⚙️ Configuration Parameters

| **Parameter**            | **Type**         | **Default** | **Description**                                                   |
|--------------------------|------------------|-------------|-------------------------------------------------------------------|
| `$width`                 | int              | 320         | CAPTCHA image width                                               |
| `$height`                | int              | 200         | CAPTCHA image height                                              |
| `$length`                | int              | 4           | Number of target icons users must click                           |
| `$decoyIconCount`        | int              | 2           | Number of decoy (non-answer) icons                                |
| `$iconSet`               | IconSetInterface | null        | Custom icon set instance                                          |
| `$backgroundImageFolder` | string           | null        | Path to custom background image folder                            |
| `$useWebp`               | bool             | true        | Prefer WebP format (smaller size); fallback to PNG if unsupported |

## 🎨 Advanced Usage

### Custom Background Images

By default, the library uses built-in random backgrounds. To use your own background images:

```php
// Specify folder containing JPG/PNG background images
$bgFolder = __DIR__ . '/assets/backgrounds';

$captcha = icon_captcha_generate(
    backgroundImageFolder: $bgFolder
);
```

### Custom Icon Fonts

The default `IconSet` uses a built-in FontAwesome-compatible font. To use your own `.ttf` font:

1. **Create a Custom IconSet Class**
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
     * Define your icon mapping
     * Key: icon alias (for debugging)
     * Value: hexadecimal Unicode code point in the TTF file
     */
    public function getIcons(): array
    {
        return [
            "star"   => "e001", 
            "heart"  => "e002",
            "camera" => "e003",
            // ... add more icons
        ];
    }

    /**
     * Implement random selection logic
     */
    public function getRandom(int $count = 4): array
    {
        $icons = $this->getIcons();
        $keys = array_rand($icons, $count);

        if (!is_array($keys)) $keys = [$keys];

        $result = [];
        foreach ($keys as $key) {
            $hex = $icons[$key];
            // Convert hex to UTF-8 character
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

2. **Use in Generation**
```php
// 1. Instantiate your custom class
$myIconSet = new MyCustomIconSet('/path/to/your-custom-font.ttf');

// 2. Pass to generator
$captcha = icon_captcha_generate(
    iconSet: $myIconSet
);
```

## 📄 License

This project is licensed under the [Apache License 2.0](./LICENSE.txt).