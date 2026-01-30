<?php

namespace Petalbranch\IconCaptcha\Contract;

interface IconSetInterface
{
    /**
     * 构造函数
     *
     * @param string $fontFilePath 要使用的字体文件路径。
     * @return void
     */
    public function __construct(string $fontFilePath);

    /**
     * 获取图标名称及其对应 Unicode 值的关联数组。
     * 格式：[name => unicode]
     *
     * @return array 一个关联数组，其中键是图标名称（字符串），值是它们对应的 Unicode 值（字符串）。
     */
    public function getIcons(): array;

    /**
     * 从可用图标列表中随机获取指定数量的图标，并以数组形式返回。
     * 每个图标均根据其十六进制编码转换为对应的 UTF-8 字符表示。
     *
     * @param int $count 要获取的随机图标数量，默认为 4。
     * @return array 返回一个关联数组，键为所选图标的索引，值为该图标的 UTF-8 字符表示。
     */
    public function getRandom(int $count = 4): array;


    public function getFontPath(): string;
}