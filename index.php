<?php
declare(strict_types=1);

set_time_limit(0);

function is_valid_filename(string $filename): bool
{
    if ($filename === '' || strlen($filename) > 255) {
        return false;
    }
    if ($filename === '.' || $filename === '..') {
        return false;
    }
    if (preg_match('/[<>:"\/\\|?*\x00-\x1F]/', $filename)) {
        return false;
    }
    if (preg_match('/^(con|prn|aux|nul|com\d|lpt\d)$/i', $filename)) {
        return false;
    }

    return true;
}

function normalize_uploaded_files(array $files): array
{
    $normalized = [];
    if (!isset($files['name'])) {
        return $normalized;
    }
    if (is_array($files['name'])) {
        $count = count($files['name']);
        for ($i = 0; $i < $count; $i++) {
            if ($files['error'][$i] === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $normalized[] = [
                'name' => $files['name'][$i],
                'type' => $files['type'][$i],
                'tmp_name' => $files['tmp_name'][$i],
                'error' => $files['error'][$i],
                'size' => $files['size'][$i],
            ];
        }
    } else {
        if ($files['error'] !== UPLOAD_ERR_NO_FILE) {
            $normalized[] = $files;
        }
    }

    return $normalized;
}

function create_transparent_image(int $width, int $height)
{
    $img = imagecreatetruecolor($width, $height);
    imagealphablending($img, false);
    imagesavealpha($img, true);
    $transparent = imagecolorallocatealpha($img, 0, 0, 0, 127);
    imagefill($img, 0, 0, $transparent);
    imagealphablending($img, true);

    return $img;
}

function encode_image_png($img): string
{
    ob_start();
    imagepng($img);
    return (string) ob_get_clean();
}

function hash_image($img): string
{
    return md5(encode_image_png($img));
}

function get_image_from_upload(array $file)
{
    $data = file_get_contents($file['tmp_name']);
    if ($data === false) {
        return null;
    }
    $img = imagecreatefromstring($data);
    if ($img === false) {
        return null;
    }
    imagesavealpha($img, true);

    return $img;
}

function get_bounding_box($img, int $alpha_threshold = 0): ?array
{
    $width = imagesx($img);
    $height = imagesy($img);
    $left = $width;
    $right = 0;
    $top = -1;
    $bottom = 0;

    for ($y = 0; $y < $height; $y++) {
        for ($x = 0; $x < $width; $x++) {
            $rgba = imagecolorat($img, $x, $y);
            $alpha = ($rgba & 0x7F000000) >> 24;
            $alpha = $alpha & 0x7F;
            $is_visible = $alpha < (127 - $alpha_threshold);
            if ($is_visible) {
                if ($x < $left) {
                    $left = $x;
                }
                if ($x >= $right) {
                    $right = $x + 1;
                }
                if ($top < 0) {
                    $top = $y;
                }
                $bottom = $y + 1;
            }
        }
    }

    if ($top < 0) {
        return null;
    }

    return [$left, $top, $right, $bottom];
}

function transform_image($img, int $new_width, int $new_height, bool $flip_x, bool $flip_y)
{
    $result = $img;
    $width = imagesx($result);
    $height = imagesy($result);
    if ($new_width !== $width || $new_height !== $height) {
        $scaled = imagescale($result, $new_width, $new_height, IMG_NEAREST_NEIGHBOUR);
        if ($scaled !== false) {
            $result = $scaled;
        }
    }

    if ($flip_x) {
        imageflip($result, IMG_FLIP_HORIZONTAL);
    }
    if ($flip_y) {
        imageflip($result, IMG_FLIP_VERTICAL);
    }

    return $result;
}

function pad_image_uniform($img, int $padding)
{
    if ($padding <= 0) {
        return $img;
    }
    $width = imagesx($img);
    $height = imagesy($img);
    $padded = create_transparent_image($width + (2 * $padding), $height + (2 * $padding));
    imagecopy($padded, $img, $padding, $padding, 0, 0, $width, $height);

    return $padded;
}

function remove_numeric_suffix(string $value, int $limit): string
{
    if ($limit === -1) {
        $end = strlen($value) - 1;
        for ($i = strlen($value) - 1; $i >= 0; $i--) {
            $ch = $value[$i];
            if ($ch < '0' || $ch > '9') {
                $end = $i;
                break;
            }
        }
        return substr($value, 0, $end + 1);
    }

    $end = strlen($value) - 1;
    $checked = 0;
    for ($i = strlen($value) - 1; $i >= 0; $i--) {
        if ($checked >= $limit) {
            $end = $i;
            break;
        }
        $ch = $value[$i];
        if ($ch < '0' || $ch > '9') {
            $end = $i;
            break;
        }
        $checked++;
    }

    return substr($value, 0, $end + 1);
}

class PrefixCounter
{
    private array $prefix_map = [];

    public function add_prefix(string $prefix): int
    {
        if (array_key_exists($prefix, $this->prefix_map)) {
            $this->prefix_map[$prefix] += 1;
            return $this->prefix_map[$prefix];
        }
        $this->prefix_map[$prefix] = 0;
        return 0;
    }
}

class Node
{
    public bool $occupied = false;
    public int $x;
    public int $y;
    public int $width;
    public int $height;
    public ?Node $down = null;
    public ?Node $right = null;

    public function __construct(int $x, int $y, int $width, int $height)
    {
        $this->x = $x;
        $this->y = $y;
        $this->width = $width;
        $this->height = $height;
    }
}

class GrowingPacker
{
    private Node $root;

    public function __construct(int $width, int $height)
    {
        $this->root = new Node(0, 0, $width, $height);
    }

    public function fit(int $width, int $height): Node
    {
        $node = $this->find_node($this->root, $width, $height);
        if ($node !== null) {
            return $this->split_node($node, $width, $height);
        }

        return $this->grow_node($width, $height);
    }

    private function find_node(Node $root, int $width, int $height): ?Node
    {
        if ($root->occupied) {
            $right = $root->right ? $this->find_node($root->right, $width, $height) : null;
            if ($right !== null) {
                return $right;
            }
            return $root->down ? $this->find_node($root->down, $width, $height) : null;
        }

        if ($width <= $root->width && $height <= $root->height) {
            return $root;
        }

        return null;
    }

    private function split_node(Node $node, int $width, int $height): Node
    {
        $node->occupied = true;
        $node->down = new Node($node->x, $node->y + $height, $node->width, $node->height - $height);
        $node->right = new Node($node->x + $width, $node->y, $node->width - $width, $height);

        return $node;
    }

    private function grow_node(int $width, int $height): Node
    {
        $root_w = $this->root->width;
        $root_h = $this->root->height;

        $can_down = $width <= $root_w;
        $can_right = $height <= $root_h;

        $should_right = $can_right && ($root_h > ($root_w + $width));
        $should_down = $can_down && ($root_w > ($root_h + $height));

        if ($should_right) {
            return $this->grow_right($width, $height);
        }
        if ($should_down) {
            return $this->grow_down($width, $height);
        }
        if ($can_right) {
            return $this->grow_right($width, $height);
        }
        if ($can_down) {
            return $this->grow_down($width, $height);
        }

        throw new RuntimeException('Unable to pack rectangle.');
    }

    private function grow_right(int $width, int $height): Node
    {
        $root_w = $this->root->width;
        $root_h = $this->root->height;

        $new_root = new Node(0, 0, $root_w + $width, $root_h);
        $new_root->occupied = true;
        $new_root->down = $this->root;
        $new_root->right = new Node($root_w, 0, $width, $root_h);
        $this->root = $new_root;

        $node = $this->find_node($this->root, $width, $height);
        if ($node === null) {
            throw new RuntimeException('Unable to pack rectangle.');
        }

        return $this->split_node($node, $width, $height);
    }

    private function grow_down(int $width, int $height): Node
    {
        $root_w = $this->root->width;
        $root_h = $this->root->height;

        $new_root = new Node(0, 0, $root_w, $root_h + $height);
        $new_root->occupied = true;
        $new_root->right = $this->root;
        $new_root->down = new Node(0, $root_h, $root_w, $height);
        $this->root = $new_root;

        $node = $this->find_node($this->root, $width, $height);
        if ($node === null) {
            throw new RuntimeException('Unable to pack rectangle.');
        }

        return $this->split_node($node, $width, $height);
    }

    public function get_root(): Node
    {
        return $this->root;
    }
}

function pack_rectangles(array $rects): array
{
    if (count($rects) === 0) {
        return [0, 0, []];
    }

    usort($rects, function ($a, $b) {
        $area_a = $a['width'] * $a['height'];
        $area_b = $b['width'] * $b['height'];
        return $area_b <=> $area_a;
    });

    $packer = new GrowingPacker($rects[0]['width'], $rects[0]['height']);
    $fits = [];

    foreach ($rects as $rect) {
        $node = $packer->fit($rect['width'], $rect['height']);
        $fits[$rect['id']] = [
            'x' => $node->x,
            'y' => $node->y,
            'width' => $rect['width'],
            'height' => $rect['height'],
        ];
    }

    $root = $packer->get_root();

    return [$root->width, $root->height, $fits];
}

function zero_fill_num(int $num, int $to_len): string
{
    $num_str = (string) $num;
    $zeros_to_insert = $to_len - strlen($num_str);
    if ($zeros_to_insert <= 0) {
        return $num_str;
    }

    return str_repeat('0', $zeros_to_insert) . $num_str;
}

function output_download(string $filename, string $content_type, string $data): void
{
    header('Content-Type: ' . $content_type);
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($data));
    echo $data;
    exit;
}

function handle_icongrid(array $post, array $files): array
{
    $errors = [];
    $icons = normalize_uploaded_files($files['icon_files'] ?? []);
    if (count($icons) === 0) {
        $errors[] = 'Please upload at least one icon.';
        return $errors;
    }

    $legacy_mode = isset($post['legacy_mode']) && $post['legacy_mode'] === '1';
    $icon_images = [];
    foreach ($icons as $icon_file) {
        $img = get_image_from_upload($icon_file);
        if ($img === null) {
            $errors[] = 'Failed to read one of the icons.';
            continue;
        }
        $icon_images[] = $img;
    }

    if (count($errors) > 0) {
        return $errors;
    }

    $icon_size_default = 150;

    if ($legacy_mode) {
        if (!isset($files['icongrid_base']) || $files['icongrid_base']['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Please upload a legacy icongrid base image.';
            return $errors;
        }
        $icongrid_img = get_image_from_upload($files['icongrid_base']);
        if ($icongrid_img === null) {
            $errors[] = 'Failed to read icongrid base image.';
            return $errors;
        }

        $icons_per_row = intdiv(imagesx($icongrid_img), $icon_size_default);
        $rows_available = intdiv(imagesy($icongrid_img), $icon_size_default);
        $bbox = get_bounding_box($icongrid_img, 0);
        if ($bbox === null) {
            $bottom_row_idx = 0;
        } else {
            $bottom_row_idx = intdiv($bbox[3], $icon_size_default);
        }

        $bottom_row_img = imagecrop($icongrid_img, [
            'x' => 0,
            'y' => $icon_size_default * $bottom_row_idx,
            'width' => imagesx($icongrid_img),
            'height' => $icon_size_default,
        ]);
        $bottom_row_bbox = $bottom_row_img ? get_bounding_box($bottom_row_img, 0) : null;
        $right_col_idx = $bottom_row_bbox ? intdiv($bottom_row_bbox[2], $icon_size_default) : 0;
        $cur_total_idx = ($bottom_row_idx * $icons_per_row) + $right_col_idx;

        foreach ($icon_images as $icon) {
            $cur_total_idx++;
            $row = intdiv($cur_total_idx, $icons_per_row);
            $col = $cur_total_idx % $icons_per_row;
            if ($row >= $rows_available) {
                $new_height = ($row + 1) * $icon_size_default;
                $new_icongrid = create_transparent_image(imagesx($icongrid_img), $new_height);
                imagecopy($new_icongrid, $icongrid_img, 0, 0, 0, 0, imagesx($icongrid_img), imagesy($icongrid_img));
                $icongrid_img = $new_icongrid;
                $rows_available = intdiv(imagesy($icongrid_img), $icon_size_default);
            }

            $icon_width = imagesx($icon);
            $icon_height = imagesy($icon);
            $insertion_x = $col * $icon_size_default;
            $insertion_y = $row * $icon_size_default;
            if ($icon_width < $icon_size_default) {
                $insertion_x += intdiv($icon_size_default - $icon_width, 2);
            }
            if ($icon_height < $icon_size_default) {
                $insertion_y += intdiv($icon_size_default - $icon_height, 2);
            }
            imagecopy($icongrid_img, $icon, $insertion_x, $insertion_y, 0, 0, $icon_width, $icon_height);
        }

        $png = encode_image_png($icongrid_img);
        output_download('iconGrid.png', 'image/png', $png);
    }

    $total_width = 0;
    $max_height = 0;
    foreach ($icon_images as $icon) {
        $total_width += imagesx($icon);
        $max_height = max($max_height, imagesy($icon));
    }

    $final = create_transparent_image($total_width, $max_height);
    $cur_x = 0;
    foreach ($icon_images as $icon) {
        $width = imagesx($icon);
        $height = imagesy($icon);
        imagecopy($final, $icon, $cur_x, 0, 0, 0, $width, $height);
        $cur_x += $width;
    }

    $png = encode_image_png($final);
    output_download('icon-grid.png', 'image/png', $png);

    return $errors;
}

function handle_spritesheet(array $post, array $files): array
{
    $errors = [];
    $charname = trim((string) ($post['character_name'] ?? ''));
    if ($charname === '' || !is_valid_filename($charname)) {
        $errors[] = 'Please provide a valid character name.';
        return $errors;
    }

    $padding = max(0, (int) ($post['padding'] ?? 2));
    $prefix_type = $post['prefix_type'] ?? 'no-prefix';
    $custom_prefix = trim((string) ($post['custom_prefix'] ?? ''));
    $use_prefix_on_xml = isset($post['use_prefix_on_xml']) && $post['use_prefix_on_xml'] === '1';
    $unique_frames_only = isset($post['unique_frames_only']) && $post['unique_frames_only'] === '1';
    $clip_to_bbox = isset($post['clip_to_bbox']) && $post['clip_to_bbox'] === '1';
    $xml_trim = (int) ($post['xml_trim'] ?? -1);

    $action = $post['spritesheet_action'] ?? '';
    if (!in_array($action, ['generate_xml', 'generate_sequence'], true)) {
        $errors[] = 'Please choose a valid spritesheet action.';
        return $errors;
    }

    $single_frames = normalize_uploaded_files($files['single_frames'] ?? []);
    $spritesheet_pngs = normalize_uploaded_files($files['spritesheet_pngs'] ?? []);
    $spritesheet_xmls = normalize_uploaded_files($files['spritesheet_xmls'] ?? []);

    if (count($single_frames) === 0 && count($spritesheet_pngs) === 0) {
        $errors[] = 'Please upload PNG frames or spritesheets.';
        return $errors;
    }

    $spritesheet_map = [];
    foreach ($spritesheet_pngs as $png) {
        $base = pathinfo($png['name'], PATHINFO_FILENAME);
        $spritesheet_map[strtolower($base)] = $png;
    }
    $xml_map = [];
    foreach ($spritesheet_xmls as $xml) {
        $base = pathinfo($xml['name'], PATHINFO_FILENAME);
        $xml_map[strtolower($base)] = $xml;
    }

    $frames = [];
    $frame_index = 0;
    $image_cache = [];

    foreach ($single_frames as $png) {
        $img = get_image_from_upload($png);
        if ($img === null) {
            $errors[] = 'Failed to read one of the PNG frames.';
            continue;
        }
        $width = imagesx($img);
        $height = imagesy($img);
        $prefix = pathinfo($png['name'], PATHINFO_FILENAME);
        $frames[] = [
            'image' => $img,
            'animation_prefix' => $prefix,
            'apply_prefix' => true,
            'frame_rect' => [
                'frame_x' => 0,
                'frame_y' => 0,
                'frame_width' => $width,
                'frame_height' => $height,
            ],
            'transform' => [
                'new_width' => $width,
                'new_height' => $height,
                'flip_x' => false,
                'flip_y' => false,
            ],
            'index' => $frame_index++,
        ];
    }

    foreach ($spritesheet_map as $base => $png) {
        if (!isset($xml_map[$base])) {
            $errors[] = 'Spritesheet ' . $png['name'] . ' is missing a matching XML file.';
            continue;
        }
        $spritesheet_img = get_image_from_upload($png);
        if ($spritesheet_img === null) {
            $errors[] = 'Failed to read spritesheet image ' . $png['name'] . '.';
            continue;
        }
        $xml = simplexml_load_file($xml_map[$base]['tmp_name']);
        if ($xml === false) {
            $errors[] = 'Failed to parse XML file ' . $xml_map[$base]['name'] . '.';
            continue;
        }

        foreach ($xml->SubTexture as $sub) {
            $name = (string) $sub['name'];
            $rect_x = (int) $sub['x'];
            $rect_y = (int) $sub['y'];
            $rect_w = (int) $sub['width'];
            $rect_h = (int) $sub['height'];
            $frame_x = (int) ($sub['frameX'] ?? 0);
            $frame_y = (int) ($sub['frameY'] ?? 0);
            $frame_w = (int) ($sub['frameWidth'] ?? $rect_w);
            $frame_h = (int) ($sub['frameHeight'] ?? $rect_h);

            $prefix = remove_numeric_suffix($name, $xml_trim);
            $cropped = imagecrop($spritesheet_img, [
                'x' => $rect_x,
                'y' => $rect_y,
                'width' => $rect_w,
                'height' => $rect_h,
            ]);
            if ($cropped === false) {
                continue;
            }
            $frames[] = [
                'image' => $cropped,
                'animation_prefix' => $prefix,
                'apply_prefix' => $use_prefix_on_xml,
                'frame_rect' => [
                    'frame_x' => $frame_x,
                    'frame_y' => $frame_y,
                    'frame_width' => $frame_w,
                    'frame_height' => $frame_h,
                ],
                'transform' => [
                    'new_width' => $rect_w,
                    'new_height' => $rect_h,
                    'flip_x' => false,
                    'flip_y' => false,
                ],
                'index' => $frame_index++,
            ];
        }
    }

    if (count($errors) > 0) {
        return $errors;
    }

    $per_frame_prefix = '';
    if ($prefix_type === 'character-name') {
        $per_frame_prefix = $charname . ' ';
    } elseif ($prefix_type === 'custom-prefix' && $custom_prefix !== '') {
        $per_frame_prefix = $custom_prefix . ' ';
    }

    $frames_by_hash = [];
    $frame_data_by_index = [];
    foreach ($frames as $frame) {
        $effective_prefix = ($frame['apply_prefix'] ? $per_frame_prefix : '') . $frame['animation_prefix'];
        $img = transform_image(
            $frame['image'],
            $frame['transform']['new_width'],
            $frame['transform']['new_height'],
            $frame['transform']['flip_x'],
            $frame['transform']['flip_y']
        );
        $bbox = get_bounding_box($img, 0);
        if ($bbox !== null) {
            [$left, $top, $right, $bottom] = $bbox;
            if ($clip_to_bbox) {
                $img = imagecrop($img, [
                    'x' => $left,
                    'y' => $top,
                    'width' => $right - $left,
                    'height' => $bottom - $top,
                ]);
                if ($img === false) {
                    continue;
                }
            }
            $img = pad_image_uniform($img, $padding);
            $hash = hash_image($img);
            if (!isset($image_cache[$hash])) {
                $image_cache[$hash] = $img;
            }
            $frame_data = [
                'hash' => $hash,
                'animation_prefix' => $effective_prefix,
                'frame_rect' => [
                    'frame_x' => $frame['frame_rect']['frame_x'] - ($left - $padding),
                    'frame_y' => $frame['frame_rect']['frame_y'] - ($top - $padding),
                    'frame_width' => $frame['frame_rect']['frame_width'],
                    'frame_height' => $frame['frame_rect']['frame_height'],
                ],
                'index' => $frame['index'],
            ];
            $frames_by_hash[$hash][] = $frame_data;
            $frame_data_by_index[$frame['index']] = $frame_data;
        } else {
            $empty_img = create_transparent_image(4, 4);
            $hash = hash_image($empty_img);
            if (!isset($image_cache[$hash])) {
                $image_cache[$hash] = $empty_img;
            }
            $frame_data = [
                'hash' => $hash,
                'animation_prefix' => $effective_prefix,
                'frame_rect' => $frame['frame_rect'],
                'index' => $frame['index'],
            ];
            $frames_by_hash[$hash][] = $frame_data;
            $frame_data_by_index[$frame['index']] = $frame_data;
        }
    }

    $rects = [];
    foreach ($image_cache as $hash => $img) {
        $rects[] = [
            'id' => $hash,
            'width' => imagesx($img),
            'height' => imagesy($img),
        ];
    }

    [$final_width, $final_height, $fits] = pack_rectangles($rects);
    $base = create_transparent_image($final_width, $final_height);

    foreach ($fits as $hash => $fit) {
        $img = $image_cache[$hash];
        imagecopy($base, $img, $fit['x'], $fit['y'], 0, 0, $fit['width'], $fit['height']);
    }

    if ($action === 'generate_xml') {
        $doc = new DOMDocument('1.0', 'utf-8');
        $doc->formatOutput = true;
        $atlas = $doc->createElement('TextureAtlas');
        $atlas->setAttribute('imagePath', $charname . '.png');
        $doc->appendChild($atlas);
        $atlas->appendChild($doc->createComment(' Created using the Spritesheet and XML generator '));
        $atlas->appendChild($doc->createComment(' https://uncertainprod.github.io/FNF-Spritesheet-XML-generator-Web '));

        $prefix_counter = new PrefixCounter();
        ksort($frame_data_by_index);
        foreach ($frame_data_by_index as $frame_index_key => $frame_data) {
            $hash = $frame_data['hash'];
            if (!isset($fits[$hash])) {
                continue;
            }
            $fit = $fits[$hash];
            $prefix_base = $frame_data['animation_prefix'];
            $suffix = zero_fill_num($prefix_counter->add_prefix($prefix_base), 4);
            $sub = $doc->createElement('SubTexture');
            $sub->setAttribute('name', $prefix_base . $suffix);
            $sub->setAttribute('x', (string) $fit['x']);
            $sub->setAttribute('y', (string) $fit['y']);
            $sub->setAttribute('width', (string) $fit['width']);
            $sub->setAttribute('height', (string) $fit['height']);
            $sub->setAttribute('frameX', (string) $frame_data['frame_rect']['frame_x']);
            $sub->setAttribute('frameY', (string) $frame_data['frame_rect']['frame_y']);
            $sub->setAttribute('frameWidth', (string) $frame_data['frame_rect']['frame_width']);
            $sub->setAttribute('frameHeight', (string) $frame_data['frame_rect']['frame_height']);
            $atlas->appendChild($sub);
        }

        $xml_content = $doc->saveXML();
        $png_content = encode_image_png($base);
        $zip = new ZipArchive();
        $tmp = tempnam(sys_get_temp_dir(), 'spsh');
        if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
            return ['Failed to create ZIP archive.'];
        }
        $zip->addFromString($charname . '.png', $png_content);
        $zip->addFromString($charname . '.xml', $xml_content ?: '');
        $zip->close();

        $zip_data = file_get_contents($tmp);
        if ($zip_data === false) {
            return ['Failed to read ZIP archive.'];
        }
        unlink($tmp);
        output_download($charname . '.zip', 'application/zip', $zip_data);
    }

    $zip = new ZipArchive();
    $tmp = tempnam(sys_get_temp_dir(), 'seq');
    if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
        return ['Failed to create ZIP archive.'];
    }

    if ($unique_frames_only) {
        foreach ($image_cache as $hash => $img) {
            $zip->addFromString('image_frame-' . $hash . '.png', encode_image_png($img));
        }
    } else {
        $prefix_counter = new PrefixCounter();
        foreach ($frames_by_hash as $hash => $frame_list) {
            foreach ($frame_list as $frame_data) {
                $img = $image_cache[$hash];
                $frame_rect = $frame_data['frame_rect'];
                $final_width = $frame_rect['frame_width'] + max($frame_rect['frame_x'], 0);
                $final_height = $frame_rect['frame_height'] + max($frame_rect['frame_y'], 0);
                $place_x = $frame_rect['frame_x'] < 0 ? -$frame_rect['frame_x'] : 0;
                $place_y = $frame_rect['frame_y'] < 0 ? -$frame_rect['frame_y'] : 0;
                $final_frame = create_transparent_image($final_width, $final_height);
                imagecopy($final_frame, $img, $place_x, $place_y, 0, 0, imagesx($img), imagesy($img));
                $seq = $prefix_counter->add_prefix($frame_data['animation_prefix']);
                $zip->addFromString($frame_data['animation_prefix'] . $seq . '.png', encode_image_png($final_frame));
            }
        }
    }

    $zip->close();
    $zip_data = file_get_contents($tmp);
    if ($zip_data === false) {
        return ['Failed to read ZIP archive.'];
    }
    unlink($tmp);
    output_download($charname . '.zip', 'application/zip', $zip_data);

    return $errors;
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form_type = $_POST['form_type'] ?? '';
    if ($form_type === 'icongrid') {
        $errors = handle_icongrid($_POST, $_FILES);
    } elseif ($form_type === 'spritesheet') {
        $errors = handle_spritesheet($_POST, $_FILES);
    } else {
        $errors[] = 'Unknown form submission.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FNF Spritesheet & XML Generator (PHP)</title>
    <link rel="stylesheet" href="assets/app.css">
</head>
<body class="dark-mode">
<main>
    <h1 id="title">FNF Spritesheet and XML Generator (PHP Legacy Build)</h1>

    <label for="dark-mode">
        <input type="checkbox" name="dark-mode" id="dark-mode" checked>
        Dark Mode
    </label>

    <?php if (count($errors) > 0): ?>
        <div class="error-box">
            <h2>There were problems with your submission:</h2>
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?php echo htmlspecialchars($error, ENT_QUOTES); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div id="app-tabs">
        <button class="tab-button active" data-tab="spritesheet">Spritesheet Generation</button>
        <button class="tab-button" data-tab="icongrid">Icongrid Generation</button>
    </div>

    <section id="tab-spritesheet" class="tab-section active">
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="form_type" value="spritesheet">
            <div class="form-section">
                <label for="character-name">Character Name</label>
                <input type="text" id="character-name" name="character_name" placeholder="character" required>
            </div>

            <div class="form-grid">
                <label for="padding">Padding (px)</label>
                <input type="number" min="0" id="padding" name="padding" value="2">

                <label for="prefix-type">Frame Prefix</label>
                <select id="prefix-type" name="prefix_type">
                    <option value="no-prefix">No Prefix</option>
                    <option value="character-name">Character Name</option>
                    <option value="custom-prefix">Custom Prefix</option>
                </select>

                <label for="custom-prefix">Custom Prefix</label>
                <input type="text" id="custom-prefix" name="custom_prefix" placeholder="idle">

                <label for="xml-trim">Trim Numeric Suffix (XML)</label>
                <input type="number" id="xml-trim" name="xml_trim" value="-1">
            </div>

            <div class="form-grid">
                <label>
                    <input type="checkbox" name="use_prefix_on_xml" value="1">
                    Apply prefix to XML frames
                </label>
                <label>
                    <input type="checkbox" name="unique_frames_only" value="1">
                    Export unique frames only (PNG sequence)
                </label>
                <label>
                    <input type="checkbox" name="clip_to_bbox" value="1" checked>
                    Clip to bounding box
                </label>
            </div>

            <div class="form-section">
                <label for="single-frames">Add standalone PNG frames</label>
                <input type="file" id="single-frames" name="single_frames[]" accept="image/png" multiple>
            </div>

            <div class="form-section">
                <label for="spritesheet-pngs">Spritesheet PNGs</label>
                <input type="file" id="spritesheet-pngs" name="spritesheet_pngs[]" accept="image/png" multiple>
            </div>

            <div class="form-section">
                <label for="spritesheet-xmls">Spritesheet XMLs (matching names)</label>
                <input type="file" id="spritesheet-xmls" name="spritesheet_xmls[]" accept="text/xml" multiple>
            </div>

            <div class="button-row">
                <button type="submit" name="spritesheet_action" value="generate_xml">Generate XML + Spritesheet ZIP</button>
                <button type="submit" name="spritesheet_action" value="generate_sequence">Generate PNG Sequence ZIP</button>
            </div>
        </form>
    </section>

    <section id="tab-icongrid" class="tab-section">
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="form_type" value="icongrid">
            <div class="form-section">
                <label>
                    <input type="checkbox" name="legacy_mode" value="1" id="legacy-mode">
                    Legacy Mode (append to an existing icongrid)
                </label>
            </div>

            <div class="form-section" id="legacy-upload" hidden>
                <label for="icongrid-base">Legacy Icongrid Base</label>
                <input type="file" id="icongrid-base" name="icongrid_base" accept="image/png">
            </div>

            <div class="form-section">
                <label for="icon-files">Icons to pack</label>
                <input type="file" id="icon-files" name="icon_files[]" accept="image/png" multiple required>
            </div>

            <div class="button-row">
                <button type="submit">Generate Icongrid</button>
            </div>
        </form>
    </section>
</main>

<script src="assets/app.js"></script>
</body>
</html>
