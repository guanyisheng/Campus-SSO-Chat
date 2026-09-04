<?php
declare(strict_types=1);

/** @return array<string, array{label:string,prompt:string}> */
function media_image_style_catalog(): array
{
    return [
        'default' => [
            'label'  => '默认',
            'prompt' => 'masterpiece, best quality, ultra high resolution, 8k, extremely detailed, natural lighting, golden ratio composition, rich depth, smooth color gradients, premium look, sharp focus, clean image',
        ],
        'portrait' => [
            'label'  => '人像摄影',
            'prompt' => 'professional portrait photography, DSLR, shallow depth of field, soft natural lighting, refined facial features, realistic skin texture, detailed hair, elegant minimal background, balanced composition, photorealistic, subtle film grain',
        ],
        'cinematic' => [
            'label'  => '电影写真',
            'prompt' => 'cinematic still, widescreen composition, film grain, dramatic lighting, strong contrast, preserved shadow detail, natural bokeh, storytelling mood, rich color grading, professional cinematography',
        ],
        'chinese' => [
            'label'  => '中国风',
            'prompt' => 'traditional Chinese aesthetic, classical oriental atmosphere, elegant negative space, muted traditional palette, ink-wash accents, ancient patterns, pavilion and landscape elements, graceful brushwork, poetic mood, guofeng style',
        ],
        'anime' => [
            'label'  => '动漫',
            'prompt' => 'anime style, clean lineart, vivid colors, detailed illustration, expressive character design, smooth shading, soft dreamy lighting, cohesive anime aesthetic, highly detailed',
        ],
        '3d' => [
            'label'  => '3D渲染',
            'prompt' => '3d render, octane render, unreal engine, PBR materials, global illumination, high-poly modeling, realistic textures, distinct metal fabric and skin materials, deep volumetric lighting, strong sense of space, cinematic render quality',
        ],
        'cyberpunk' => [
            'label'  => '赛博朋克',
            'prompt' => 'cyberpunk style, futuristic cityscape, neon lights, rain-soaked streets, holographic displays, mechanical elements, cool color palette, high contrast, moody atmospheric lighting, dystopian sci-fi vibe',
        ],
        'cg' => [
            'label'  => 'CG 动画',
            'prompt' => 'CG animation style, Hollywood VFX quality, ultra-detailed rendering, dynamic particles, dramatic lighting, epic scale, rich materials, natural motion, strong depth, sci-fi fantasy aesthetic',
        ],
        'ink' => [
            'label'  => '水墨画',
            'prompt' => 'traditional Chinese ink painting, sumi-e, expressive brush strokes, layered ink tones, natural ink diffusion, minimalist composition, poetic landscape or figure, elegant monochrome, classical oriental art',
        ],
        'oil' => [
            'label'  => '油画',
            'prompt' => 'oil painting, thick impasto texture, visible brush strokes, vintage color palette, strong chiaroscuro, classical fine art, rich saturated colors, museum-quality realism',
        ],
        'classical' => [
            'label'  => '古典',
            'prompt' => 'classical European art, Renaissance aesthetic, ornate architecture and costumes, soft desaturated tones, gentle elegant lighting, symmetrical composition, dignified atmosphere, refined historical detail',
        ],
        'watercolor' => [
            'label'  => '水彩画',
            'prompt' => 'watercolor painting, hand-painted, soft color bleeds, visible paper texture, light delicate strokes, pastel palette, airy gentle mood, blended edges, artistic illustration',
        ],
        'cartoon' => [
            'label'  => '卡通',
            'prompt' => 'cartoon style, rounded clean lines, cute simplified shapes, bright cheerful colors, flat illustration, clear outlines, playful friendly mood, clean simple composition',
        ],
    ];
}

function media_image_style_keys(): array
{
    return array_keys(media_image_style_catalog());
}

function media_image_style_normalize_key(string $key): string
{
    $key = trim($key);
    $catalog = media_image_style_catalog();
    if ($key !== '' && isset($catalog[$key])) {
        return $key;
    }
    return 'default';
}

function media_image_negative_prompt(): string
{
    return 'blurry, low quality, low resolution, bad anatomy, deformed, disfigured, extra limbs, watermark, text, logo, jpeg artifacts, oversaturated, cluttered, ugly';
}

/** 主体描述 + 风格提示词（拼接规则） */
function media_compose_image_prompt(string $subject, string $styleKey = 'default'): string
{
    $subject = trim($subject);
    $styleKey = media_image_style_normalize_key($styleKey);
    $stylePrompt = trim(media_image_style_catalog()[$styleKey]['prompt'] ?? '');

    if ($subject === '') {
        return $stylePrompt;
    }
    if ($stylePrompt === '') {
        return $subject;
    }

    return $subject . ', ' . $stylePrompt;
}
