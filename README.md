# IconCaptcha


## 安装
```bash
composer require petalbranch/icon-captcha
```

## 快速开始

- 生成示例
    ```php
    <?php
    // 生成验证码数据包
    $captcha = icon_captcha_generate();
    [$id,$image_b64,$icons,$answer] = $captcha;
    
    // 缓存答案数组（2分钟内有效）
    Redis::setex("ic:$id", 60 * 2, $answer);
    
    // 返回前端，前端示例:`<img src="data:image/png;base64,{{image_b64}}" />`
    echo json_encode(['id'=>$id,'image'=>$image_b64,'icons'=>$icons])；
    ```

- 验证示例
    ```php
    <?php
    // 只做演示，必须拿到ID和点击位置组
    $params = $request->post();
  
    // 省略入参验证...
  
    $id = $params['id']; # captcha_id 用于从缓存中提取答案组
    $click_positions = $params['click_positions']; # 前端点击位置组, 格式[[x,y],[x,y]...] 或者 格式[{x:x1,y:y1},{x:x2,y:y2}...]
  
    // 省略缓存是否存在验证  
  
    // 取出答案组 
    $answer = Redis::get("ic:$id");
    $answer = json_decode($answer,true);
  
    if (icon_captcha_verify($answer,$click_positions)){
        // 验证通过
    }else{
        // 验证失败
    }
    ```