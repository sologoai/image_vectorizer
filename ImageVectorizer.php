<?php
/**
 * Color bitmap vectorization using KMeans++ algorithm based on the Potrace project
 * @author SologoVision
 * See TODO for areas that can be further optimized
 * Current limitations:
 * 1. May lose graphics when processing gradient colors
 * 2. May produce noise with few colors (2-3) if k value is inappropriate
 * @example Usage example
 * 
 */
class ImageVectorizer{
    /**
     * Black and white: store bitmap
     */
    public $bm = NULL;
    /**
     * Black and white: store path list
     * @var array
     */
    public $pathlist = array();
    /*
     * Color: bitmap layered by color
     * {
     *   'rgb_string1'=>Bitmap,
     *   'rgb_string2'=>Bitmap,
     *   ...
     * }
     */
    private $_colors_bms = null;
    /*
     * Color: cache each layer's path svg code
     */
    private $_colors_path_layers = null;
    /*
     * Current image handle
     */
    private $res = null;
    /*
     * Current image dimensions
     */
    private $width = 0;
    private $height = 0;
    // Image type: jpg, png
    private $image_type = ''; 
    /*
     * Maximum image side length: if exceeded, need to shrink first then process, then restore svg dimensions after processing
     * Purpose: improve calculation speed, avoid memory overflow
     */
    private $max_side_length = 512;
    // Color mode: shrink ratio when image size exceeds max_side_length limit
    private $scale_back = 0;
    /*
     * Background color: rgb format
     */
    private $_bg_color = null;
    /*
     * Foreground colors: color list
     * [
     *  ['rgb'=>[0,0,0],'rgb_string'=>'0,0,0','hex'=>'#000000','hsl'=>[0,0,0],'num'=>50.00],
     *  ...
     * ]
     */
    private $_fe_colors = [];
    /*
     * Color mapping table
     * {
     *  ori_rgb_string=>new_rgb_string,
     *  ...
     * }
     *
     */
    private $_color_mapping = [];
    /*
     * Deleted colors: need to put in color mapping table to avoid holes or gaps in canvas
     * TODO: Currently almost no effect, temporarily not used
     */
    private $_colors_removed = [];
    /**
     * Configuration
     * @var array
     */
    public $config = array(
        /*
         * Below are Potrace algorithm parameters
         */
        // Mode: black=black and white, default, color=color
        'mode'=>'color',
        // Turn policy: default minority, black, white, left, right, minority, majority ("black" / "white" / "left" / "right" / "minority" / "majority")
        'turnpolicy' => "minority",
        // Noise size: noise to eliminate, unit px (suggest setting to 1 for color mode)
        'turdsize' => 2,
        // Whether to enable curve optimization: suggest enable
        'optcurve' => TRUE,
        // Corner (vertex/angle) threshold: default 1, larger value smoother but may distort
        'alphamax' => 1,
        // Curve optimization tolerance:
        'opttolerance' => 0.2,
        // Bitmap generation method: blacklevel(brightness), bgcolor(foreground/background contrast), balance(balance)
        'bitmap_type'=>'balance',
        // Brightness threshold: effective when bitmap_type=blacklevel, default 128, range 0-255
        'black_level'=>128,
        // Foreground/background contrast: effective when bitmap_type=bgcolor, default 120, range 0-441
        'bgcolor_level'=>120,
        // Balance: effective when bitmap_type=balance, default 128, range 0-441
        'balance_level'=>128,
        /*
         * Below are KMeans++ color clustering algorithm parameters
         */
        /*
         * Cluster upper limit, larger is slower, should be >=colorsNum
         * Maximum suggest=10 (running speed within 1s)
         */
        'clusters_num'	=> 10,
        'clusters_num_max'	=> 20,
        'clusters_num_min'	=> 5,
        // Color quantity: default 5, range 1-16, currently needs to be consistent with clusters_num, after algorithm modification clusters_num >= color_size
        'color_size'    =>5,
        /*
         * Kmeans intra-cluster iteration threshold, smaller value more iterations (slower)
         */
        'kmeans_difference_distance'=> 5,
        /*
         * Gap repair value: default 0, suggest range -10~20
         * Larger value more likely to repair gaps but also causes color merging; smaller value larger gaps but may cause color loss
         */
        'kmeans_gap_fix_value'=>5,
        /*
         * Whether to use Lab color space for Kmeans calculation, Lab is more accurate than RGB space
         */
        'kmeans_use_lab_color'=>true,
        // Noise removal: remove colors reaching this ratio (0-100), default 0.5
        'keep_color_rate_min' => 0.5,
        // Color mode: similar color distance threshold
        'similar_color_distance' => 40, //0-441
        // Color mode: distance threshold from background color when extracting colors, 0-441
        'bg_color_distance' => 35, //0-441
        // Hue merging: minimum H difference to merge
        'merge_color_hue_min' => 5,        
        // Color mode: whether to perform hue merging
        'merge_color_hue'=>true,
        // Color mode: whether to remove background
        'remove_background'=>false,
    );
    /**
     * SVG generation parameters
     * @var array
     */
    public $svg_args = array(
        'scale_size'=>1,       // Scale ratio
        'path_type'=>'fill',
        'fill_color'=>'black', // Black and white mode: fill color
    );
    private $_debug = false;
    /*
     * Whether conversion succeeded flag
     */
    private $_trace_success = false;
    /**
     * Constructor
     * @param array $config Parameter configuration
     */
    function __construct($config=array()){
        $this->setConfig($config);
    }
    public function setDebug($flag){
        $this->_debug = $flag;
    }
    public function setMaxSideLength($lenth){
        $this->max_side_length = $lenth;
    }
    /**
     * Set parameters
     * @param array $config Algorithm configuration
     */
    public function setConfig($config){
        $this->config = (object) array_merge((array) $this->config, $config);
        /*
         * Relationship between clusters_num and color_size:
         * clusters_num = Min(color_size * 2, clusters_num_max);
         */
        if ($this->config->clusters_num < $this->config->color_size){
            $this->config->clusters_num = $this->config->color_size;
        }else{
            $this->config->clusters_num = $this->config->color_size * 2;
            $this->config->clusters_num = min($this->config->clusters_num,$this->config->clusters_num_max);
        }
        if( $this->config->clusters_num < $this->config->clusters_num_min) $this->config->clusters_num = $this->config->clusters_num_min;
    }
    /**
     * Load image
     * @param string $filepath Absolute path filepath
     * @return int status
     * 0=success
     * 1=parameter empty
     * 2=file doesn't exist
     * 3=cannot draw SVG
     */
    public function loadImageFromFile($filepath){
        if (empty($filepath)) return 1;
        if (!file_exists($filepath)) return 2;
        $this->res = $this->_load_image($filepath);
        if (empty($this->res)) return false;
        if ($this->config->mode == 'color'){
            // Color mode
            $re = $this->_load_bitmap_colors();
        }else{
            // Black and white/monochrome mode
            $re = $this->_load_bitmap();
        }
        if (!$re){
            return 3;
        }
        return 0;
    }
    /**
     * Return image color information (effective in color mode)
     * @return mixed
     * false=failure: non-color mode or SVG generation failure
     * array={
     *  [bg_color] => #b4dfff,
        [color_list] => [
                [0] => #002657
                [1] => #ffffff
                [2] => #2aa3f4
            ]
     * }
     */
    public function getImageColors(){
        if ($this->config->mode != 'color') return false;
        if (!$this->_trace_success) return false;
        $re = array();
        $re['bg_color'] = $this->rgb2hex($this->_bg_color);
        $re['color_list'] = [];
        foreach ($this->_fe_colors as $color){
            $re['color_list'][] = $color['hex'];
        }
        return $re;
    }
    /**
     * Return generated SVG HTML code
     * @param array $args Generation parameters
     * {
     *  scale_size: 1,          // Scale, multiple of original size
     *  path_type: "fill",      // Color drawing method: fill=fill color, curve=draw stroke, default fill
     *  fill_color: "black",    // Black and white mode: fill color, default black, supports "black","#ff0000","rgb(255,0,255)" format
     * }
     * @return mixed
     * false=failure
     * string=svg html code
     */
    public function getSVG($args=[]){
        if (empty($this->res)) return false;
        if (!empty($args)) $this->svg_args = array_merge($this->svg_args,$args);
        if ($this->config->mode == 'color'){
            // color mode
            $this->process_colors($this->svg_args['scale_size'],$this->svg_args['path_type']);
        }else{
            // black mode
            $this->process();
        }
        return $this->getSVGHtml($this->svg_args['scale_size'],$this->svg_args['path_type'],$this->svg_args['fill_color'],true);
    }

    /**
     * Determine if it's a valid svg
     * @return bool true=valid, false=invalid
     */
    public function is_valid_svg(){
        if($this->config->mode == 'color'){
            return !empty($this->_colors_bms);
        }
        if($this->config->mode == 'black'){
            return array_sum($this->bm->data) > 0;
        }
        return false;
    }

    /**
     * Black and white: path drawing
     */
    public function process() {
        $this->bmToPathlist();
        $this->processPath();
    }
    /**
     * Color: execute path drawing by color layers
     */
    public function process_colors($scale_size=1,$path_type='fill') {
        if (!empty($this->_colors_bms)){
            $this->_colors_path_layers = [];
            foreach($this->_colors_bms as $rgb_string=>$bitmaps){
                if (empty($rgb_string) || empty($bitmaps)) continue;
                $this->bm = $bitmaps;
                // trace path
                $this->bmToPathlist();
                $this->processPath();
                // generate svg path DOM
                $hex = $this->rgb2hex(explode(',', $rgb_string));
                $this->_colors_path_layers[] = $this->getPathDom($scale_size,$path_type,$hex);
                // clear current bitmap and path
                $this->clear();
            }
        }
    }
    /**
     * Only clear bitmap and pathlist
     */
    public function clear() {
        $this->bm = null;
        $this->pathlist = array();
    }
    /**
     * Clear all
     */
    public function clearAll(){
        $this->bm = null;
        $this->pathlist = array();
        $this->res = null;
        $this->_colors_bms = null;
        $this->_colors_path_layers = null;
        $this->width = 0;
        $this->height = 0;
        $this->image_type = '';
        $this->_color_mapping = [];
        $this->_colors_removed = [];
        $this->scale_back = 0;
        $this->_bg_color = null;
        $this->_fe_colors = [];
        $this->_trace_success = false;
    }
    /**
     * Get SVG code
     * @param int $scale_size Scale, multiple of original size
     * @param string $path_type Color drawing method
     * =curve use stroke to draw color
     * =fill use fill to draw color, default fill
     * @param string $fill_color Fill color, default black, supports "black","#ff0000","rgb(255,0,255)" format
     * @param boolean $auto_clear Automatically clear process data
     * @return string
     */
    public function getSVGHtml($scale_size=1, $path_type='fill',$fill_color='black',$auto_clear=true) {
        list($new_width,$new_height) = $this->_get_new_size($scale_size);
        $svg = '<?xml version="1.0" encoding="UTF-8"?>
<svg id="svg" version="1.1" width="' . $new_width . '" height="' . $new_height .
'" viewBox="0 0 '.$new_width.' '.$new_height.'" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink">';
        if ($this->config->mode == 'color'){ // color mode
            // add bg color
            if (!$this->config->remove_background){
                // there is bgcolor
                $svg .= $this->_getBackgroundDom($scale_size, $this->_bg_color);
            }
            // add color layers
            foreach($this->_colors_path_layers as $path_svg){
                if (empty($path_svg)) continue;
                $svg .= $path_svg;
            }
        }else{ // black mode
            $svg .= $this->getPathDom($scale_size,$path_type,$fill_color);
        }

        $svg .= '</svg>';
        if ($auto_clear) $this->clearAll();
        return $svg;
    }
    /*===============================================Below are algorithm codes==========================================*/
    /*
     * Calculate scaled dimensions
     * @return [width,height]
     */
    private function _get_new_size($scale_size=1){
        if ($scale_size == 1) return [$this->width,$this->height];
        return [round($this->width * $scale_size),round($this->height * $scale_size)];
    }
    private function _load_image($filepath){
        $img_info = getimagesize($filepath);
        if (empty($img_info)) return false;
        $width_ori = $img_info[0];
        $height_ori = $img_info[1];
        $this->width = $width_ori;
        $this->height = $height_ori;
        $this->image_type = strtolower(image_type_to_extension($img_info[2],false));
        // Color mode: check if image size exceeds limit
        if ($this->config->mode == 'color'){
            $check_size = $this->_check_image_size($width_ori, $height_ori);
            if ($check_size['scale'] > 0){
                // Shrink image and save shrink ratio
                $this->width = $check_size['width'];
                $this->height = $check_size['height'];
                $this->scale_back = $check_size['scale']; // Shrink ratio
            }
        }
        // load image
        $source_img_handle = imagecreatefromstring(file_get_contents($filepath));
        if ($this->scale_back > 0){
            // Shrink image
            $target_img_handle = imagecreatetruecolor($this->width, $this->height);
            // Handle transparent alpha channel
            if ($this->image_type == 'png'){
                imagealphablending($target_img_handle, false);
                imagesavealpha($target_img_handle, true);
            }
            if (!imagecopyresampled($target_img_handle, $source_img_handle, 0, 0, 0, 0, $this->width, $this->height, $width_ori, $height_ori)){
                return false;
            }
            return $target_img_handle;
        }
        return $source_img_handle;
    }
    /*
     * Check if width/height dimensions exceed, if so provide shrunk width/height and shrink ratio
     */
    private function _check_image_size($w,$h){
        $re = array(
            'width'=>$w,
            'height'=>$h,
            'scale'=>0,
        );
        $max_side = max($w,$h);
        if ($max_side <= $this->max_side_length){
            return $re;
        }
        $re['scale'] = $this->max_side_length/$max_side;
        $re['width'] = round($w * $re['scale']);
        $re['height'] = round($h * $re['scale']);
        return $re;
    }
    /*
     * Generate background svg code
     */
    private function _getBackgroundDom($scale_size,$bg_color){
        list($new_width,$new_height) = $this->_get_new_size($scale_size);
        return '<g id="background"><rect x="0" y="0" fill="'.$this->rgb2hex($bg_color).'" width="'.$new_width.'" height="'.$new_height.'"></rect></g>';
    }
    /**
     * Generate graphics part svg code
     * @param int $scale_size
     * @param string $path_type
     * @param string $fill_color e.g. 'black', '#ff0000', 'rgb(255,0,0)'
     * @return string
     */
    public function getPathDom($scale_size,$path_type,$fill_color='black'){
        $dom = '<path d="';
        $dom .= $this->getPath($scale_size);
        if ($path_type === "curve") {
            $strokec = $fill_color;
            $fillc = "none";
            $fillrule = '';
        } else {
            $strokec = "none";
            $fillc = $fill_color;
            $fillrule = ' fill-rule="evenodd"';
        }
        $dom .= '" stroke="' . $strokec . '" fill="' . $fillc . '"' . $fillrule . '/>';
        return $dom;
    }
    /**
     * Get path path
     * @param number $scale_size Scale size, default 1
     * @return string
     */
    public function getPath($scale_size=1){
        $pathlist = &$this->pathlist;
        $path = function($curve) use($scale_size) {
            $bezier = function($i) use($curve, $scale_size) {
                $b = 'C ' . number_format($curve->c[$i * 3 + 0]->x * $scale_size, 3, ".", "") . ' ' .
                    number_format($curve->c[$i * 3 + 0]->y * $scale_size, 3, ".", "") . ',';
                    $b .= number_format($curve->c[$i * 3 + 1]->x * $scale_size, 3, ".", "") . ' ' .
                        number_format($curve->c[$i * 3 + 1]->y * $scale_size, 3, ".", "") . ',';
                        $b .= number_format($curve->c[$i * 3 + 2]->x * $scale_size, 3, ".", "") . ' ' .
                            number_format($curve->c[$i * 3 + 2]->y * $scale_size, 3, ".", "") . ' ';
                            return $b;
            };
            $segment = function($i) use ($curve, $scale_size) {
                $s = 'L ' . number_format($curve->c[$i * 3 + 1]->x * $scale_size, 3, ".", "") . ' ' .
                    number_format($curve->c[$i * 3 + 1]->y * $scale_size, 3, ".", "") . ' ';
                    $s .= number_format($curve->c[$i * 3 + 2]->x * $scale_size, 3, ".", "") . ' ' .
                        number_format($curve->c[$i * 3 + 2]->y * $scale_size, 3, ".", "") . ' ';
                        return $s;
            };
            $n = $curve->n;
            $p = 'M' . number_format($curve->c[($n - 1) * 3 + 2]->x * $scale_size, 3, ".", "") .
            ' ' . number_format($curve->c[($n - 1) * 3 + 2]->y * $scale_size, 3, ".", "") . ' ';
            for ($i = 0; $i < $n; $i++) {
                if ($curve->tag[$i] === "CURVE") {
                    $p .= $bezier($i);
                } else if ($curve->tag[$i] === "CORNER") {
                    $p .= $segment($i);
                }
            }
            return $p;
        };
        $len = count($pathlist);
        $path_str = '';
        for ($i = 0; $i < $len; $i++) {
            $c = $pathlist[$i]->curve;
            $path_str .= $path($c);
        }
        return $path_str;
    }
    /*
     * Black and white/monochrome mode - calculate and load image bitmap
     */
    private function _load_bitmap(){
        if (empty($this->res)) return false;
        if (empty($this->width) || empty($this->height)) return false;
        $this->bm = new Bitmap($this->width, $this->height);
        // Get background color first
        $bg_rgb = $this->get_background_color();
        $this->_bg_color = $bg_rgb;
        /*
         * Divide pixels into 0/1 two states by condition, 0=don't draw, 1=draw
         * Note: bitmap array index is position, must ensure Points quantity in bitmap
         */
        for($i=0; $i<$this->height; $i++){
            for($j=0; $j<$this->width; $j++){
                $rgb_index = imagecolorat($this->res, $j, $i);
                $rgb = $this->_index2rgb($rgb_index);
                if ($this->config->bitmap_type == 'bgcolor'){
                    // Algorithm: foreground/background color difference
                    $this->bm->data[] = $this->_get_bmbit_by_bgcolor($rgb,$bg_rgb);
                }else if ($this->config->bitmap_type == 'balance'){
                    // Algorithm: balance
                    $this->bm->data[] = $this->_get_bmbit_by_balance($rgb,$bg_rgb);
                }else{
                    // Algorithm: brightness
                    $this->bm->data[] = $this->_get_bmbit_by_blacklevel($rgb);
                }
            }
        }
        return $this->_trace_success;
    }
    /*
     * Color mode - calculate and load image bitmap
     * Process by color layers
     */
    private function _load_bitmap_colors(){
        if (empty($this->res)) return false;
        if (empty($this->width) || empty($this->height)) return false;
        // Get background color first
        $this->_bg_color = $this->get_background_color();
        /*
         * Extract foreground colors, exclude background color
         */
        $color_list = $this->_get_color_cluster();
        if (empty($color_list)){
            return false;
        }
        $this->_fe_colors = $color_list;
        $this->_trace_success = true;
        /* 
         * Classify pixels into 0/1 states by condition, 0=don't draw, 1=draw
         * Note: bitmap array index is position, must ensure Points quantity in bitmap
         */
        //init color bit maps
        $this->_colors_bms = [];
        foreach ($color_list as $color) {
            if (!isset($this->_colors_bms[$color['rgb_string']])) $this->_colors_bms[$color['rgb_string']] = new Bitmap($this->width, $this->height);
        }
        /*
         * Single color check: Used to optimize bitmap generation for single-color images, only need to distinguish background and foreground
         */
        $is_one_color = count($color_list) == 1 ? true : false;
        /*
         * Generate color layer bitmap
         */
        //Pixel and color matching distance threshold: color similarity distance + gap repair value
        $color_match_threshold = $this->config->similar_color_distance + $this->config->kmeans_gap_fix_value;
        for($i=0; $i<$this->height; $i++){
            for($j=0; $j<$this->width; $j++){
                $rgb_index = imagecolorat($this->res, $j, $i);
                $rgb = $this->_index2rgb_by_res($rgb_index);
                $rgb_string = implode(',', $rgb);
                 //Draw color bitmap layer by layer
                foreach ($color_list as $color) {
                    $color_rgb_string = $color['rgb_string'];
                    //Background color doesn't need validation
                    $distance_bg = $this->get_color_distance($rgb, $this->_bg_color);
                    if ($distance_bg <= $this->config->bg_color_distance){
                        $this->_colors_bms[$color_rgb_string]->data[] = 0;
                    }else{
                        //Handle single color
                        if ($is_one_color){
                            $this->_colors_bms[$color_rgb_string]->data[] = 1;
                            continue;
                        }
                        //Handle multiple colors
                        $distance = $this->get_color_distance($rgb, $color['rgb']);
                        if ($distance <= $color_match_threshold){
                            $this->_colors_bms[$color_rgb_string]->data[] = 1;
                        }else{
                            //Pixel doesn't match color: first check color mapping
                            if (!empty($this->_color_mapping)){
                                $mapping_rgb_string = $this->_get_mapping_target_color_string($rgb_string);
                                if (!empty($mapping_rgb_string) && $mapping_rgb_string == $color_rgb_string){
                                    //Found mapped color: set color bit
                                    $this->_colors_bms[$color_rgb_string]->data[] = 1;
                                    continue;
                                }
                            }
                            /*
                             * TODO: Unmatched color: cannot simply ignore, need to detect if current pixel is valid color
                             * - If all ignored, may lose colors
                             * - If all used, may produce noise
                             * Option 1: All used
                             * Option 2: cluster_num > color_size, ensure to get all valid colors (larger proportion), detect if current pixel color is in cluster, then consider valid pixel otherwise ignore
                             */
                            //set non color bit
                            $this->_colors_bms[$color_rgb_string]->data[] = 0;
                        }
                    }
                }
            }
        }
        return true;
    }
    /*
     * Calculate the minimum distance from pixel to other colors in palette excluding current color
     */
    private function _get_min_distance_color_list(&$pixel_rgb,&$current_color,&$color_list){
        $min_distance = 9999999;
        foreach ($color_list as $color) {
            //不算当前颜色
            if ($color['rgb_string'] == $current_color['rgb_string']) continue;
            $distance = $this->get_color_distance($pixel_rgb, $color['rgb']);
            if ($distance < $min_distance) $min_distance = $distance;
        }
        return $min_distance;
    }
    /*
     * Find the most similar color in color mapping table
     */
    private function _get_mapping_target_color_string($rgb_string){
        if (isset($this->_color_mapping[$rgb_string])) return $this->_color_mapping[$rgb_string];
        $color_rgb = explode(',', $rgb_string);
        $distance_min = 999;
        $most_similar_rgb_string = '';
        foreach ($this->_color_mapping as $ori_rgb_string=>$target_rgb_string){
            $ori_rgb = explode(',', $ori_rgb_string);
            $distance = $this->get_color_distance($ori_rgb, $color_rgb);
            if ($distance < $distance_min && $distance < $this->config->similar_color_distance){
                $distance_min = $distance;
                $most_similar_rgb_string = $target_rgb_string;
            }
        }
        return $most_similar_rgb_string;
    }
    /*
     * Color clustering
     * @return color_list
     */
    private function _get_color_cluster(){
        $color_list = array(); 
        $pixels = array(); 
        for ($x = 0; $x < $this->width; $x++) {
            for ($y = 0; $y < $this->height; $y++) {
                $rgb_index = imagecolorat($this->res, $x, $y);
                $rgb = $this->_index2rgb_by_res($rgb_index);
                //check is bgcolor
                $distance = $this->get_color_distance($rgb, $this->_bg_color);
                if ($distance <= $this->config->bg_color_distance) continue;
                if ($this->config->kmeans_use_lab_color){
                    //Use Lab color space: more accurate
                    $pixels[] = $this->rgb2lab($rgb[0], $rgb[1], $rgb[2]);
                }else{
                    //Use RGB color space: faster
                    $pixels[] = $rgb;
                }
                
            }
        }
        if (empty($pixels)) return $color_list;
        $color_list = $this->_kmeans($pixels);
        $color_list = $this->_calculate_RateAndSort($color_list);
        $color_list = $this->_merge_SpeckleAndGray($color_list);
        if ($this->config->color_size > 0 && count($color_list) > $this->config->color_size){
            $color_list = $this->_get_color_change_mapping($color_list);
        }
        if ($this->config->mode == 'color' && $this->config->remove_background){
            $color_list = $this->_check_color_visible($color_list,$this->_bg_color);
        }
        return $color_list;
    }
    /*
     * K-means++
     * @return array
     * [
     *  {
     *      'rgb'=>[255,0,0],
     *      'num=>120, 
     *  },
     *  ...
     * ]
     */
    private function _kmeans($pixels) {
        $numPixels = count($pixels);
        /*
         * Random initial cluster center
         */
        $initalPixel = $pixels[floor(rand(0, $numPixels - 1))];
        /*
         * Random initial cluster, cluster format:
         * [
         *    [255,255,255], //pixel point RGB
         *    [point1,point2,point3,...] //all pixel RGBs in cluster, point=[r,g,b]
         * ]
         */
        $init_cluster = array($initalPixel, array($initalPixel));
        //Cluster list
        $clusters = array();
        $clusters[] = $init_cluster;
        //Must set default value, otherwise infinite loop
        if (empty($this->config->clusters_num)) $this->config->clusters_num = 5;
        if (empty($this->config->kmeans_difference_distance)) $this->config->kmeans_difference_distance = 5;
        /*
         * Initialize cluster centers
         */
        while (count($clusters) < $this->config->clusters_num) {
            $pixelList = array();
            $cumulativeSquareDistance = 0;
            foreach ($pixels as $pixel) {
                $smallest_distance = 99999999;
                foreach ($clusters as $cluster) {
                    $distance = $this->get_color_distance($pixel, $cluster[0]);
                    if ($distance < $smallest_distance) {
                        $smallest_distance = $distance;
                    }
                }
                $cumulativeSquareDistance += $smallest_distance * $smallest_distance;
                $pixelList[] = array($cumulativeSquareDistance, $pixel);
            }
            $randomPick = rand(0, $cumulativeSquareDistance);
            while (count($pixelList) > 1) {
                $randomIndex = rand(0, count($pixelList) - 1);
                if ($pixelList[$randomIndex][0] < $randomPick) {
                    $pixelList = array_slice($pixelList, $randomIndex + 1);
                } else if ($pixelList[$randomIndex][0] > $randomPick) {
                    $pixelList = array_slice($pixelList, 0, $randomIndex + 1);
                } else {
                    $pixelList = array_slice($pixelList, $randomIndex, 1);
                }
            }
            $pixel = $pixelList[0][1];
            $clusters[] = array($pixel, array($pixel));
        }
        /*
         * Kmeans
         */
        $iteration = 0;
        while (true) {
            $pixelLists = array_fill(0, $this->config->clusters_num, array());
            for ($i = 0; $i < $numPixels; $i++) {
                $pixel = $pixels[$i];
                $smallest_distance = 99999999;
                $closestIndex = 0;
                for ($j = 0; $j < $this->config->clusters_num; $j++) {
                    $distance = $this->get_color_distance($pixel, $clusters[$j][0]);
                    if ($distance < $smallest_distance) {
                        $smallest_distance = $distance;
                        $closestIndex = $j;
                    }
                }
                $pixelLists[$closestIndex][] = $pixel;
            }
            $difference = 0;
            for ($i = 0; $i < $this->config->clusters_num; $i++) {
                $oldCluster = $clusters[$i];
                $newCenter = $this->_kmeans_get_center($pixelLists[$i]);
                $clusters[$i] = array($newCenter, $pixelLists[$i]);
                $distanceToOldClusterCenter = $this->get_color_distance($oldCluster[0], $newCenter);
                $difference = ($difference > $distanceToOldClusterCenter) ? $difference : $distanceToOldClusterCenter;
            }
            usort($clusters, function($a, $b) {
                $sizea = count($a[1]);
                $sizeb = count($b[1]);
                if ($sizea == $sizeb) {
                    return 0;
                }
                return ($sizea > $sizeb) ? -1 : 1;
            });
            $iteration++;
            if ($difference < $this->config->kmeans_difference_distance) {
                break;
            }
        }
        //Generate color list
        $color_list = [];
        foreach ($clusters as $cluster){
            if ($this->config->kmeans_use_lab_color){
                $rgb = $this->lab2rgb($cluster[0][0], $cluster[0][1], $cluster[0][2]);
            }else{
                $rgb = $cluster[0];
            }
            $color_list[] = array(
                'rgb'=>$rgb,
                'rgb_string'=>implode(',', $rgb),
                'hsl'=>$this->rgb2hsl($rgb),
                'hex'=>$this->rgb2hex($rgb),
                'num'=>count($cluster[1]),
            );
        }
        return $color_list;
    }
    /*
     * KMeans: Calculate center point
     */
    private function _kmeans_get_center($colors) {
        $n = count($colors);
        if ($n > 0) {
            $channels = array(0, 0, 0);
            for ($i = 0; $i < $n; $i++) {
                $channels[0] += $colors[$i][0];
                $channels[1] += $colors[$i][1];
                $channels[2] += $colors[$i][2];
            }
            $channels[0] = round($channels[0] / $n);
            $channels[1] = round($channels[1] / $n);
            $channels[2] = round($channels[2] / $n);
            return $channels;
        }
        
        return array(0, 0, 0);
    }
    /*
     * Validate color visibility: in color mode + remove background
     */
    private function _check_color_visible($color_list,$bg_color){
        $new_color_list = [];
        //Prepare replacement color: default black
        $replace_color = array(
            'rgb_string'=>'0,0,0',
            'rgb'=>[0,0,0],
            'hex'=>'#000000',
            'hsl'=>[0,0,0],
            'num'=>0,
        );
        //Whether to use background color as replacement
        if (!$this->_check_color_like_white($bg_color)){
            //Background has color: use background as replacement
            $replace_color = array(
                'rgb_string'=>implode(',', $bg_color),
                'rgb'=>$bg_color,
                'hex'=>$this->rgb2hex($bg_color),
                'hsl'=>$this->rgb2hsl($bg_color),
                'num'=>0,
            );
        }
        //Check if color is white: replace white with background and black
        foreach ($color_list as $color){
            if ($this->_check_color_like_white($color['rgb'])){
                $replace_color['num'] = $color['num'];
                $new_color_list[] = $replace_color;
                //add color mapping table
                $this->_add_color_mapping($color['rgb_string'], $replace_color['rgb_string']);
            }else{
                //Foreground not white: keep unchanged
                $new_color_list[] = $color;
            }
        }
        return $new_color_list;
    }
    /*
     * Handle color quantity limit
     * draw exceeded colors as most similar color
     * @return array Original-new color mapping table
     */
    private function _get_color_change_mapping($color_list){
        if ($this->config->color_size < 1) return $color_list;
        $len = count($color_list);
        if ($len <= $this->config->color_size) return $color_list;
        $this->_sort_color_list($color_list);
        $keep_list = array_slice($color_list,0,$this->config->color_size,true);
        /*
         * TODO: Can be optimized
         * May merge small proportion valid colors, color H difference of 60 can be considered different colors
         */
        //Colors to merge
        $merge_list = array_slice($color_list,$this->config->color_size ,$len - $this->config->color_size,true);
        foreach ($merge_list as $merge_color){
            $target_color = $this->_get_color_mapping_one($merge_color, $keep_list);
            if (empty($target_color)) continue;
            $this->_add_color_mapping($merge_color['rgb_string'], $target_color['rgb_string']);
        }
        return $keep_list;
    }
    /*
     * Add a color mapping
     */
    private function _add_color_mapping($ori_rgb_string,$new_rgb_string){
        if (empty($ori_rgb_string) || empty($new_rgb_string)) return ;
        if (isset($this->_color_mapping[$ori_rgb_string])) return;
        $this->_color_mapping[$ori_rgb_string] = $new_rgb_string;
        return;
    }
    /*
     * Calculate most similar color
     */
    private function _get_color_mapping_one($merge_color,$color_list){
        $min_distance = 9999999;
        $min_color = false;
        foreach ($color_list as $color){
            $distance = $this->get_color_distance($merge_color['rgb'], $color['rgb']);
            if ($distance < $min_distance){
                $min_distance = $distance;
                $min_color = $color;
            }
        }
        return $min_color;
    }

    /*
     * Check if all are grayscale colors
     */
    private function _check_is_all_grey(&$color_list){
        $is_grey = true;
        foreach ($color_list as $color){
            if (!$this->_check_is_grey($color['hsl'])){
                $is_grey = false;
                break;
            }
        }
        return $is_grey;
    }
    /*
     *  Check if color is grayscale or near-grayscale
     * @param array $hsl [h,s,l]
     */
    private function _check_is_grey($hsl){
        //Black, white: consider as grayscale
        if ($hsl[2] >= 95 || $hsl[2] <= 5) return true;
        //Saturation minimum judgment
        if ($hsl[1] <= 10) return true;
        return false;
    }
   /**
    * RGB to HSL
    * @param array $rgb
    * @return array(H,S,L)
    */
    public function rgb2hsl($rgb)
    {
        $R = $rgb[0] / 255.0;
        $G = $rgb[1] / 255.0;
        $B = $rgb[2] / 255.0;
        $vMin = min($R, $G, $B);
        $vMax = max($R, $G, $B);
        $dMax = $vMax - $vMin;
        $half_dMax = $dMax / 2.0;
        $L = ($vMax + $vMin) / 2.0;
        
        if ($dMax == 0) {
            $H = 0;
            $S = 0;
        } else {
            if ($L < 0.5){
                $S = $dMax / ($vMax + $vMin);
            }else{
                $S = $dMax / (2 - $vMax - $vMin);
            }
            $dR = ((($vMax - $R) / 6.0) + $half_dMax) / $dMax;
            $dG = ((($vMax - $G) / 6.0) + $half_dMax) / $dMax;
            $dB = ((($vMax - $B) / 6.0) + $half_dMax) / $dMax;
            if ($R == $vMax){
                $H = $dB - $dG;
            }else if ($G == $vMax){
                $H = (1 / 3.0) + $dR - $dB;
            }else if ($B == $vMax){
                $H = (2 / 3.0) + $dG - $dR;
            }
            if ($H < 0){
                $H = $H + 1;
            }
            if ($H > 1){
                $H = $H - 1;
            }
        }
        $result = array(
            round(360 * $H),
            round($S * 100),
            round($L * 100)
        );
        return $result;
    }
    /*
     * Check if two RGB colors are similar
     * @param array $rgb1
     * @param array $rgb2
     * @return boolean
     * true=similar
     */
    private function _check_color_similar($rgb1,$rgb2){
        $distance = $this->get_color_distance($rgb1, $rgb2);
        return $distance <= $this->config->similar_color_distance;
    }
    /**
     * RGB to hex
     */
    public function rgb2hex($rgb,$prefix='#')
    {
        if ($rgb[0] > 255) $rgb[0] = 255;
        if ($rgb[1] > 255) $rgb[1] = 255;
        if ($rgb[2] > 255) $rgb[2] = 255;
        if ($rgb[0] < 0) $rgb[0] = 0;
        if ($rgb[1] < 0) $rgb[1] = 0;
        if ($rgb[2] < 0) $rgb[2] = 0;
        return $prefix. str_pad(dechex($rgb[0]), 2, '0', STR_PAD_LEFT) . str_pad(dechex($rgb[1]), 2, '0', STR_PAD_LEFT) . str_pad(dechex($rgb[2]), 2, '0', STR_PAD_LEFT);
    }
    /**
     * RGB to XYZ
     * @return number[]
     */
    public function  rgb2xyz($r, $g, $b) {
        $r = $r / 255;
        $g = $g / 255;
        $b = $b / 255;
        
        $r = ($r > 0.04045) ? pow((($r + 0.055) / 1.055), 2.4) : ($r / 12.92);
        $g = ($g > 0.04045) ? pow((($g + 0.055) / 1.055), 2.4) : ($g / 12.92);
        $b = ($b > 0.04045) ? pow((($b + 0.055) / 1.055), 2.4) : ($b / 12.92);
        
        $x = $r * 0.4124 + $g * 0.3576 + $b * 0.1805;
        $y = $r * 0.2126 + $g * 0.7152 + $b * 0.0722;
        $z = $r * 0.0193 + $g * 0.1192 + $b * 0.9505;
        
        return [$x, $y, $z];
    }
    /**
     * XYZ to LAB
     * @return number[]
     */
    public function xyz2lab($x, $y, $z) {
        $refX = 0.95047;
        $refY = 1.00000;
        $refZ = 1.08883;
        
        $x = $x / $refX;
        $y = $y / $refY;
        $z = $z / $refZ;
        
        $x = ($x > 0.008856)? pow($x, 1 / 3) : (7.787 * $x + 16 / 116);
        $y = ($y > 0.008856)? pow($y, 1 / 3) : (7.787 * $y + 16 / 116);
        $z = ($z > 0.008856)? pow($z, 1 / 3) : (7.787 * $z + 16 / 116);
        
        $L = (116 * $y) - 16;
        $a = 500 * ($x - $y);
        $b = 200 * ($y - $z);
        
        return [$L, $a, $b];
    }
    /**
     * RGB to LAB
     * @return number[]
     */
    public function rgb2lab($r, $g, $b) {
        list($x, $y, $z) = $this->rgb2xyz($r, $g, $b);
        return $this->xyz2lab($x, $y, $z);
    }
    //LAB to XYZ
    public function lab2xyz($L, $a, $b) {
        $refX = 0.95047;
        $refY = 1.00000;
        $refZ = 1.08883;
        
        $y = ($L + 16) / 116;
        $x = $a / 500 + $y;
        $z = $y - $b / 200;
        
        $x = $refX * (($x * $x * $x > 0.008856)? $x * $x * $x : (($x - 16 / 116) / 7.787));
        $y = $refY * (($y * $y * $y > 0.008856)? $y * $y * $y : (($y - 16 / 116) / 7.787));
        $z = $refZ * (($z * $z * $z > 0.008856)? $z * $z * $z : (($z - 16 / 116) / 7.787));
        
        return [$x, $y, $z];
    }
    // XYZ to RGB
    public function xyz2rgb($x, $y, $z) {
        $r = $x *  3.2406 + $y * -1.5372 + $z * -0.4986;
        $g = $x * -0.9689 + $y *  1.8758 + $z *  0.0415;
        $b = $x *  0.0557 + $y * -0.2040 + $z *  1.0570;
        
        $r = ($r > 0.0031308)? (1.055 * pow($r, 1 / 2.4) - 0.055) : (12.92 * $r);
        $g = ($g > 0.0031308)? (1.055 * pow($g, 1 / 2.4) - 0.055) : (12.92 * $g);
        $b = ($b > 0.0031308)? (1.055 * pow($b, 1 / 2.4) - 0.055) : (12.92 * $b);
        
        $r = min(1, max(0, $r));
        $g = min(1, max(0, $g));
        $b = min(1, max(0, $b));
        
        $r = round($r * 255);
        $g = round($g * 255);
        $b = round($b * 255);
        
        return [$r, $g, $b];
    }
    // LAB to RGB
    public function lab2rgb($L, $a, $b) {
        list($x, $y, $z) = $this->lab2xyz($L, $a, $b);
        return $this->xyz2rgb($x, $y, $z);
    }
    private function _calculate_RateAndSort($color_list){
        $total = 0;
        foreach ($color_list as $color){
            $total += $color['num'];
        }
        //calculate rate
        foreach ($color_list as &$color){
            $color['num'] = round(($color['num'] / $total) * 100, 2);
        }
        $this->_sort_color_list($color_list);
        return $color_list;
    }
    /*
     * sort by num
     */
    private function _sort_color_list(&$color_list){
        usort($color_list, function ($a, $b) {
            return $b['num'] - $a['num'];
        });
    }
    /*
     * sort by rate
     */
    private function _sort_by_rate(&$palette)
    {
        $total = array_sum($palette);
        foreach ($palette as $rgb_str => $count) {
            $palette[$rgb_str] = round(($count / $total) * 100, 2);
        }
        arsort($palette);
    }
    /*
     * merge speckle and gray
     */
    private function _merge_SpeckleAndGray($color_list){
        $new_color_list = array();
        /*
         * Special handling for all grayscale colors: Currently the best result is achieved by keeping only the most dominant color, otherwise various noise and rough edges will appear
         * Reason: Pure black and grayscale colors cannot use the same logic
         */
        $is_all_grey = $this->_check_is_all_grey($color_list);
        if ($is_all_grey){
            $main_color_string = $color_list[0]['rgb_string']; //Main color
            /*
             * Grayscale optimization parameter: turnpolicy=white
             */
            $this->config->turnpolicy = 'white';
            foreach ($color_list as $color) {
                //Check similarity between color and background
                if ($this->get_color_distance($color['rgb'], $this->_bg_color) <= $this->config->bg_color_distance){
                    //Ignore this color
                    continue;
                }
                if ($main_color_string == $color['rgb_string']){
                    $new_color_list[] = $color;
                }else{
                    if ($color['num'] < $this->config->keep_color_rate_min) continue; //Noise
                    $this->_add_color_mapping($color['rgb_string'], $main_color_string);
                }
            }
            return $new_color_list;
        }
        /*
         * Non-all-grayscale with colors: Only process noise in grayscale colors
         */
        $max_gray_index = -1;
        foreach ($color_list as $index=>$color) {
            //Check similarity between color and background
            if ($this->get_color_distance($color['rgb'], $this->_bg_color) <= $this->config->bg_color_distance){
                //Ignore this color
                continue;
            }
            /*
             * Ignore colors with small proportion: may be noise
             */
            if ($color['num'] < $this->config->keep_color_rate_min) continue;
            //Keep colored
            if (!$this->_check_is_grey($color['hsl'])){
                $new_color_list[] = $color;
                continue;
            }
            /*
             * Below are all grayscale colors
             */
            //Get maximum grayscale color index
            if ($max_gray_index < 0){
                $max_gray_index = $index;
                $new_color_list[] = $color;
                continue;
            }
            //Check if other grayscale colors are noise
            if ($max_gray_index >= 0){
                if ($color_list[$max_gray_index]['num'] / $color['num'] > 5 && $color['num'] < 10){
                    //Consider as noise: ignore
                    continue;
                }
            }
            $new_color_list[] = $color;
        }
        /*
         * Merge hue
         */
        $final_color_list = [];
        $max_hue= 360;
        $half_hue = 180;
        foreach ($new_color_list as $color) {
            $found_similar_color = false;
            if ($color['num'] >= 5){
                $final_color_list[] = $color;
                continue;
            }
            foreach ($final_color_list as $exist_index=>$exist_color) {
                //Must both be colored
                if ($this->_check_is_grey($color['hsl']) || $this->_check_is_grey($exist_color['hsl'])){
                    continue;
                }
                //Merge similar colors
                $distance_h = abs($exist_color['hsl'][0] - $color['hsl'][0]);
                if ($distance_h > $half_hue) $distance_h = $max_hue - $distance_h;
                if ($distance_h <= $this->config->merge_color_hue_min && $this->get_color_distance($color['rgb'], $exist_color['rgb']<= $this->config->similar_color_distance)) {
                    $found_similar_color = true;
                    if ($color['num'] > $exist_color['num']){
                        $color['num'] += $exist_color['num'];
                        $final_color_list[] = $color;
                        unset($final_color_list[$exist_index]);
                    }else{
                        $final_color_list[$exist_index]['num'] += $color['num'];
                    }
                    break;
                }
            }
            if (!$found_similar_color) {
                $final_color_list[] = $color;
            }
        }
        return $final_color_list;
    }
    
   /*
    * Calculate RGB color darkness
    * @param array $rgb e.g. [0,255,255]
    * @return int  0~255，0 most dark，255 most light
    */
    private function _get_color_darkness($rgb){
        return round($rgb[0] * 0.299 + $rgb[1] * 0.587 + $rgb[2] * 0.114);
    }
    private function _check_color_like_white($rgb){
        $distance = $this->get_color_distance($rgb, [255,255,255]);
        return $distance < 5;
    }
    /*
     * Generate bitmap algorithm: Balance, combining brightness with foreground/background
     */
    private function _get_bmbit_by_balance($rgb,$bg_rgb){
        $bit = $this->_get_bmbit_by_blacklevel($rgb,$bg_rgb);
        if (!$bit){
            $distance = $this->get_color_distance($rgb, $bg_rgb);
            $bit = $distance > $this->config->balance_level ? 1 : 0;
        }
        if ($bit){
            if (!$this->_trace_success) $this->_trace_success = true;
        }
        return $bit;
    }
    /*
     * Generate bitmap algorithm: Calculate foreground and background difference
     */
    private function _get_bmbit_by_bgcolor($rgb,$bg_rgb){
        $distance = $this->get_color_distance($rgb, $bg_rgb);
        if ($distance > $this->config->bgcolor_level){
            if (!$this->_trace_success) $this->_trace_success = true;
            return 1;
        }
        return 0;
    }
    /*
     * Generate bitmap algorithm: Calculate brightness
     */
    private function _get_bmbit_by_blacklevel($rgb){
        $brightness = $this->get_color_brightness($rgb);
        if ($brightness < $this->config->black_level){
            if (!$this->_trace_success) $this->_trace_success = true;
            return 1;
        }
        return  0;
    }
    /*
     * Get color brightness
     */
    private function get_color_brightness($rgb){
        return (0.2126 * $rgb[0]) + (0.7153 * $rgb[1]) + (0.0721 * $rgb[2]);
    }
    /*
     * index to rgb
     */
    private function _index2rgb($index){
        $r = round( ($index >> 16) & 0xFF );
        $g = round( ($index >> 8) & 0xFF );
        $b = round( $index & 0xFF );
        if ($r > 255) $r = 255;
        if ($g > 255) $g = 255;
        if ($b > 255) $b = 255;
        return [$r,$g,$b];
    }
    /*
     * index to rgb: Can handle transparent color
     * @return [255,255,255]
     */
    private function _index2rgb_by_res($index)
    {
        $c = imagecolorsforindex($this->res, $index);
        if ($c['alpha'] >= 100) { //(0~127)透明：认为是白色
            return [255,255,255];
        } else {
            $r = round($c['red']);
            $g = round($c['green']);
            $b = round($c['blue']);
            if ($r > 255) $r = 255;
            if ($g > 255) $g = 255;
            if ($b > 255) $b = 255;
            return [$r,$g,$b];
        }
    }
    /**
     * Get background color
     * @return array [r,g,b]
     */
    public function get_background_color()
    {
        if (empty($this->res)) return false;
        $palette = array();
        //Background color sampling points
        $bg_points = $this->_get_bg_points($this->width, $this->height);
        foreach ($bg_points as $point) {
            $rgb_index = imagecolorat($this->res, $point[0], $point[1]);
            $rgb = $this->_index2rgb_by_res($rgb_index);
            $rgb_string = implode(',', $rgb);
            if (!isset($palette[$rgb_string])) $palette[$rgb_string] = 0;
            $palette[$rgb_string]++;
        }
        arsort($palette);
        $new_palette = $this->_merge_similar_color_once($palette,$this->config->similar_color_distance);
        if (count($new_palette) > 1) arsort($new_palette);
        foreach ($new_palette as $color => $c) {
            $background_color = $color;
            break;
        }
        return explode(',', $background_color);
    }
    /*
     * Merge similar colors
     * $palette = {
     *  '255,0,0'=>10,
     *  '255,0,1'=>5,
     *  ...
     * }
     */
    private function _merge_similar_color($palette)
    {
        $palette = $this->_merge_similar_color_once($palette,10);
        $palette = $this->_merge_similar_color_once($palette,$this->config->similar_color_distance);

        return $palette;
    }
    /*
     * Merge similar colors
     */
    private function _merge_similar_color_once($palette,$offset_distance=10)
    {
        $result_colors = array();
        foreach ($palette as $rgb_string => $rgb_count) {
            $found_similar_color = false;
            $color_rgb = explode(',', $rgb_string);
            foreach ($result_colors as $existing_color_string => $existing_color_count) {
                $existing_color_rgb = explode(',', $existing_color_string);
                $distance = $this->get_color_distance($existing_color_rgb, $color_rgb);
                if ($distance <= $offset_distance) {
                    $found_similar_color = true;
                    if ($rgb_count > $existing_color_count){
                        $result_colors[$rgb_string] = $rgb_count + $existing_color_count;
                        unset($result_colors[$existing_color_string]);
                    }else{
                        $result_colors[$existing_color_string] +=$rgb_count;
                    }
                    break;
                }
            }
            if (!$found_similar_color) {
                $result_colors[$rgb_string] = $rgb_count;
            }
        }
        return $result_colors;
    }
    /**
     * Get color distance
     * @param array $a [255,255,255]
     * @param array $b [255,255,255]
     * @return float
     */
    public function get_color_distance($a, $b)
    {
        $r = ($a[0] - $b[0]) * ($a[0] - $b[0]) + ($a[1] - $b[1]) * ($a[1] - $b[1]) + ($a[2] - $b[2]) * ($a[2] - $b[2]);
        return sqrt($r);
    }
    /*
     * Get background color sampling points
     * @param int $width
     * @param int $height
     * @return array
     */
    private function _get_bg_points($width, $height)
    {
        $num = 7;
        $y = 1;
        $step_width = ceil($width / $num);
        $step_height = ceil($height / $num);
        $points = array();
        for ($i = 1; $i <= $width; $i += $step_width) {
            if ($i > $width) $i = $width;
            $key = $i . '-' . $y;
            $points[$key] = array($i, $y);
        }
        $x = $width - 1;
        for ($i = 1; $i <= $height; $i += $step_height) {
            if ($i > $height) $i = $height;
            $key = $x . '-' . $i;
            $points[$key] = array($x, $i);
        }
        $y = $height - 2;
        for ($i = 1; $i <= $width; $i += $step_width) {
            if ($i > $width) $i = $width;
            $key = $i . '-' . $y;
            $points[$key] = array($i, $y);
        }
        $x = 4;
        for ($i = 1; $i <= $height; $i += $step_height) {
            if ($i > $height) $i = $height;
            $key = $x . '-' . $i;
            $points[$key] = array($x, $i);
        }
        $points = array_values($points);
        $points[] = array(ceil($width / 2), ceil($height / 2));
        return $points;
    }
    /*
     * bitmap to path list
     * @return array
     */
    private function bmToPathlist(){
        $info = $this->config;
        $bm = &$this->bm;
        $bm1 = clone $bm;
        $currentPoint = new Point(0, 0);

        $findNext = function($point) use ($bm1) {
            $i = $bm1->w * $point->y + $point->x;
            while ($i < $bm1->size && $bm1->data[$i] !== 1) {
                $i++;
            }
            if($i < $bm1->size)
                return $bm1->index($i);
                return false;
        };

        $majority = function($x, $y) use ($bm1) {
            for ($i = 2; $i < 5; $i++) {
                $ct = 0;
                for ($a = -$i + 1; $a <= $i - 1; $a++) {
                    $ct += $bm1->at($x + $a, $y + $i - 1) ? 1 : -1;
                    $ct += $bm1->at($x + $i - 1, $y + $a - 1) ? 1 : -1;
                    $ct += $bm1->at($x + $a - 1, $y - $i) ? 1 : -1;
                    $ct += $bm1->at($x - $i, $y + $a) ? 1 : -1;
                }
                if ($ct > 0) {
                    return 1;
                } else if ($ct < 0) {
                    return 0;
                }
            }
            return 0;
        };

        $findPath = function($point) use($bm, $bm1, $majority, $info) {
            $path = new Path();
            $x = $point->x;
            $y = $point->y;
            $dirx = 0; $diry = 1;

            $path->sign = $bm->at($point->x, $point->y) ? "+" : "-";

            while (1) {
                $path->pt[] = new Point($x, $y);
                if ($x > $path->maxX)
                    $path->maxX = $x;
                    if ($x < $path->minX)
                        $path->minX = $x;
                        if ($y > $path->maxY)
                            $path->maxY = $y;
                            if ($y < $path->minY)
                                $path->minY = $y;
                                $path->len++;

                                $x += $dirx;
                                $y += $diry;
                                $path->area -= $x * $diry;

                                if ($x === $point->x && $y === $point->y)
                                    break;

                                    $l = $bm1->at($x + ($dirx + $diry - 1 ) / 2, $y + ($diry - $dirx - 1) / 2);
                                    $r = $bm1->at($x + ($dirx - $diry - 1) / 2, $y + ($diry + $dirx - 1) / 2);

                                    if ($r && !$l) {
                                        if ($info->turnpolicy === "right" ||
                                            ($info->turnpolicy === "black" && $path->sign === '+') ||
                                            ($info->turnpolicy === "white" && $path->sign === '-') ||
                                            ($info->turnpolicy === "majority" && $majority($x, $y)) ||
                                            ($info->turnpolicy === "minority" && !$majority($x, $y))) {
                                                $tmp = $dirx;
                                                $dirx = - $diry;
                                                $diry = $tmp;
                                            } else {
                                                $tmp = $dirx;
                                                $dirx = $diry;
                                                $diry = - $tmp;
                                            }
                                    } else if ($r) {
                                        $tmp = $dirx;
                                        $dirx = - $diry;
                                        $diry = $tmp;
                                    } else if (!$l) {
                                        $tmp = $dirx;
                                        $dirx = $diry;
                                        $diry = - $tmp;
                                    }
            }
            return $path;
        };

        $xorPath = function ($path) use(&$bm1){
            $y1 = $path->pt[0]->y;
            $len = $path->len;

            for ($i = 1; $i < $len; $i++) {
                $x = $path->pt[$i]->x;
                $y = $path->pt[$i]->y;

                if ($y !== $y1) {
                    $minY = $y1 < $y ? $y1 : $y;
                    $maxX = $path->maxX;
                    for ($j = $x; $j < $maxX; $j++) {
                        $bm1->flip($j, $minY);
                    }
                    $y1 = $y;
                }
            }
        };

        while ($currentPoint = $findNext($currentPoint)) {
            $path = $findPath($currentPoint);

            $xorPath($path);

            if ($path->area > $info->turdsize) {
                $this->pathlist[] = $path;
            }
        }
    }
    /*
     * process path
     */
    private function processPath() {
        $info = $this->config;

        $mod = function ($a, $n) {
            return $a >= $n ? $a % $n : ($a>=0 ? $a : $n-1-(-1-$a) % $n);
        };

        $xprod = function ($p1, $p2) {
            return $p1->x * $p2->y - $p1->y * $p2->x;
        };

        $cyclic = function ($a, $b, $c) {
            if ($a <= $c) {
                return ($a <= $b && $b < $c);
            } else {
                return ($a <= $b || $b < $c);
            }
        };

        $sign = function ($i) {
            return $i > 0 ? 1 : ($i < 0 ? -1 : 0);
        };

        $quadform = function ($Q, $w) {
            $v = array_fill(0, 3, NULL);

            $v[0] = $w->x;
            $v[1] = $w->y;
            $v[2] = 1;
            $sum = 0.0;

            for ($i=0; $i<3; $i++) {
                for ($j=0; $j<3; $j++) {
                    $sum += $v[$i] * $Q->at($i, $j) * $v[$j];
                }
            }
            return $sum;
        };

        $interval = function ($lambda, $a, $b) {
            $res = new Point();

            $res->x = $a->x + $lambda * ($b->x - $a->x);
            $res->y = $a->y + $lambda * ($b->y - $a->y);
            return $res;
        };

        $dorth_infty = function ($p0, $p2) use($sign) {
            $r = new Point();

            $r->y = $sign($p2->x - $p0->x);
            $r->x = - $sign($p2->y - $p0->y);

            return $r;
        };

        $ddenom = function ($p0, $p2) use ($dorth_infty){
            $r = $dorth_infty($p0, $p2);

            return $r->y * ($p2->x - $p0->x) - $r->x * ($p2->y - $p0->y);
        };

        $dpara = function ($p0, $p1, $p2) {
            $x1 = $p1->x - $p0->x;
            $y1 = $p1->y - $p0->y;
            $x2 = $p2->x - $p0->x;
            $y2 = $p2->y - $p0->y;

            return $x1 * $y2 - $x2 * $y1;
        };

        $cprod = function ($p0, $p1, $p2, $p3) {
            $x1 = $p1->x - $p0->x;
            $y1 = $p1->y - $p0->y;
            $x2 = $p3->x - $p2->x;
            $y2 = $p3->y - $p2->y;

            return $x1 * $y2 - $x2 * $y1;
        };

        $iprod = function ($p0, $p1, $p2) {
            $x1 = $p1->x - $p0->x;
            $y1 = $p1->y - $p0->y;
            $x2 = $p2->x - $p0->x;
            $y2 = $p2->y - $p0->y;

            return $x1 * $x2 + $y1 * $y2;
        };

        $iprod1 = function ($p0, $p1, $p2, $p3) {
            $x1 = $p1->x - $p0->x;
            $y1 = $p1->y - $p0->y;
            $x2 = $p3->x - $p2->x;
            $y2 = $p3->y - $p2->y;

            return $x1 * $x2 + $y1 * $y2;
        };

        $ddist = function ($p, $q) {
            return sqrt(($p->x - $q->x) * ($p->x - $q->x) + ($p->y - $q->y) * ($p->y - $q->y));
        };

        $bezier = function ($t, $p0, $p1, $p2, $p3) {
            $s = 1 - $t; $res = new Point();

            $res->x = $s * $s * $s * $p0->x
            + 3*($s * $s * $t) * $p1->x
            + 3*($t * $t * $s) * $p2->x
            + $t * $t * $t * $p3->x;

            $res->y = $s * $s * $s * $p0->y
            + 3*($s * $s * $t) * $p1->y
            + 3*($t * $t * $s) * $p2->y
            + $t * $t * $t * $p3->y;

            return $res;
        };

        $tangent = function ($p0, $p1, $p2, $p3, $q0, $q1) use($cprod){
            $A = $cprod($p0, $p1, $q0, $q1);
            $B = $cprod($p1, $p2, $q0, $q1);
            $C = $cprod($p2, $p3, $q0, $q1);
            $a = $A - 2 * $B + $C;
            $b = -2 * $A + 2 * $B;
            $c = $A;

            $d = $b * $b - 4 * $a * $c;

            if ($a==0 || $d<0) {
                return -1.0;
            }

            $s = sqrt($d);

            if($a == 0){
                return -1.0;
            }
            $r1 = (-$b + $s) / (2 * $a);
            $r2 = (-$b - $s) / (2 * $a);

            if ($r1 >= 0 && $r1 <= 1) {
                return $r1;
            } else if ($r2 >= 0 && $r2 <= 1) {
                return $r2;
            } else {
                return -1.0;
            }
        };

        $calcSums = function (&$path) {
            $path->x0 = $path->pt[0]->x;
            $path->y0 = $path->pt[0]->y;

            $path->sums = array();
            $s = &$path->sums;
            $s[] = new Sum(0, 0, 0, 0, 0);
            for($i = 0; $i < $path->len; $i++){
                $x = $path->pt[$i]->x - $path->x0;
                $y = $path->pt[$i]->y - $path->y0;
                $s[] = new Sum($s[$i]->x + $x, $s[$i]->y + $y, $s[$i]->xy + $x * $y,
                    $s[$i]->x2 + $x * $x, $s[$i]->y2 + $y * $y);
            }
        };

        $calcLon = function (&$path) use($mod, $xprod, $sign, $cyclic){
            $n = $path->len; $pt = &$path->pt;
            $pivk = array_fill(0, $n, NULL);
            $nc = array_fill(0, $n, NULL);
            $ct = array_fill(0, 4, NULL);
            $path->lon = array_fill(0, $n, NULL);

            $constraint = array(new Point(), new Point());
            $cur = new Point();
            $off = new Point();
            $dk = new Point();

            $k = 0;
            for($i = $n - 1; $i >= 0; $i--){
                if ($pt[$i]->x != $pt[$k]->x && $pt[$i]->y != $pt[$k]->y) {
                    $k = $i + 1;
                }
                $nc[$i] = $k;
            }

            for ($i = $n - 1; $i >= 0; $i--) {
                $ct[0] = $ct[1] = $ct[2] = $ct[3] = 0;
                $dir = (3 + 3 * ($pt[$mod($i + 1, $n)]->x - $pt[$i]->x) +
                    ($pt[$mod($i + 1, $n)]->y - $pt[$i]->y)) / 2;
                    $ct[$dir]++;

                    $constraint[0]->x = 0;
                    $constraint[0]->y = 0;
                    $constraint[1]->x = 0;
                    $constraint[1]->y = 0;

                    $k = $nc[$i];
                    $k1 = $i;
                    while (1) {
                        $foundk = 0;
                        $dir =  (3 + 3 * $sign($pt[$k]->x - $pt[$k1]->x) +
                            $sign($pt[$k]->y - $pt[$k1]->y)) / 2;
                            $ct[$dir]++;

                            if ($ct[0] && $ct[1] && $ct[2] && $ct[3]) {
                                $pivk[$i] = $k1;
                                $foundk = 1;
                                break;
                            }

                            $cur->x = $pt[$k]->x - $pt[$i]->x;
                            $cur->y = $pt[$k]->y - $pt[$i]->y;

                            if ($xprod($constraint[0], $cur) < 0 || $xprod($constraint[1], $cur) > 0) {
                                break;
                            }

                            if (abs($cur->x) <= 1 && abs($cur->y) <= 1) {

                            } else {
                                $off->x = $cur->x + (($cur->y >= 0 && ($cur->y > 0 || $cur->x < 0)) ? 1 : -1);
                                $off->y = $cur->y + (($cur->x <= 0 && ($cur->x < 0 || $cur->y < 0)) ? 1 : -1);
                                if ($xprod($constraint[0], $off) >= 0) {
                                    $constraint[0]->x = $off->x;
                                    $constraint[0]->y = $off->y;
                                }
                                $off->x = $cur->x + (($cur->y <= 0 && ($cur->y < 0 || $cur->x < 0)) ? 1 : -1);
                                $off->y = $cur->y + (($cur->x >= 0 && ($cur->x > 0 || $cur->y < 0)) ? 1 : -1);
                                if ($xprod($constraint[1], $off) <= 0) {
                                    $constraint[1]->x = $off->x;
                                    $constraint[1]->y = $off->y;
                                }
                            }
                            $k1 = $k;
                            $k = $nc[$k1];
                            if (!$cyclic($k, $i, $k1)) {
                                break;
                            }
                    }
                    if ($foundk == 0) {
                        $dk->x = $sign($pt[$k]->x - $pt[$k1]->x);
                        $dk->y = $sign($pt[$k]->y - $pt[$k1]->y);
                        $cur->x = $pt[$k1]->x - $pt[$i]->x;
                        $cur->y = $pt[$k1]->y - $pt[$i]->y;

                        $a = $xprod($constraint[0], $cur);
                        $b = $xprod($constraint[0], $dk);
                        $c = $xprod($constraint[1], $cur);
                        $d = $xprod($constraint[1], $dk);

                        $j = 10000000;
                        if ($b < 0) {
                            $j = floor($a / -$b);
                        }
                        if ($d > 0) {
                            $j = min($j, floor(-$c / $d));
                        }
                        $pivk[$i] = $mod($k1+$j,$n);
                    }
            }

            $j=$pivk[$n-1];
            $path->lon[$n-1]=$j;
            for ($i=$n-2; $i>=0; $i--) {
                if ($cyclic($i+1,$pivk[$i],$j)) {
                    $j=$pivk[$i];
                }
                $path->lon[$i]=$j;
            }

            for ($i=$n-1; $cyclic($mod($i+1,$n),$j,$path->lon[$i]); $i--) {
                $path->lon[$i] = $j;
            }
        };

        $bestPolygon = function (&$path) use($mod){

            $penalty3 = function ($path, $i, $j) {
                $n = $path->len; $pt = $path->pt; $sums = $path->sums;
                $r = 0;
                if ($j>=$n) {
                    $j -= $n;
                    $r = 1;
                }

                if ($r == 0) {
                    $x = $sums[$j+1]->x - $sums[$i]->x;
                    $y = $sums[$j+1]->y - $sums[$i]->y;
                    $x2 = $sums[$j+1]->x2 - $sums[$i]->x2;
                    $xy = $sums[$j+1]->xy - $sums[$i]->xy;
                    $y2 = $sums[$j+1]->y2 - $sums[$i]->y2;
                    $k = $j+1 - $i;
                } else {
                    $x = $sums[$j+1]->x - $sums[$i]->x + $sums[$n]->x;
                    $y = $sums[$j+1]->y - $sums[$i]->y + $sums[$n]->y;
                    $x2 = $sums[$j+1]->x2 - $sums[$i]->x2 + $sums[$n]->x2;
                    $xy = $sums[$j+1]->xy - $sums[$i]->xy + $sums[$n]->xy;
                    $y2 = $sums[$j+1]->y2 - $sums[$i]->y2 + $sums[$n]->y2;
                    $k = $j+1 - $i + $n;
                }

                $px = ($pt[$i]->x + $pt[$j]->x) / 2.0 - $pt[0]->x;
                $py = ($pt[$i]->y + $pt[$j]->y) / 2.0 - $pt[0]->y;
                $ey = ($pt[$j]->x - $pt[$i]->x);
                $ex = -($pt[$j]->y - $pt[$i]->y);

                $a = (($x2 - 2*$x*$px) / $k + $px*$px);
                $b = (($xy - $x*$py - $y*$px) / $k + $px*$py);
                $c = (($y2 - 2*$y*$py) / $k + $py*$py);

                $s = $ex*$ex*$a + 2*$ex*$ey*$b + $ey*$ey*$c;

                return sqrt($s);
            };

            $n = $path->len;
            $pen = array_fill(0, $n + 1, NULL);
            $prev = array_fill(0, $n + 1, NULL);
            $clip0 = array_fill(0, $n, NULL);
            $clip1 = array_fill(0, $n + 1,  NULL);
            $seg0 = array_fill(0, $n + 1, NULL);
            $seg1 = array_fill(0, $n + 1, NULL);

            for ($i=0; $i<$n; $i++) {
                $c = $mod($path->lon[$mod($i-1,$n)]-1,$n);
                if ($c == $i) {
                    $c = $mod($i+1,$n);
                }
                if ($c < $i) {
                    $clip0[$i] = $n;
                } else {
                    $clip0[$i] = $c;
                }
            }

            $j = 1;
            for ($i=0; $i<$n; $i++) {
                while ($j <= $clip0[$i]) {
                    $clip1[$j] = $i;
                    $j++;
                }
            }

            $i = 0;
            for ($j=0; $i<$n; $j++) {
                $seg0[$j] = $i;
                $i = $clip0[$i];
            }
            $seg0[$j] = $n;
            $m = $j;

            $i = $n;
            for ($j=$m; $j>0; $j--) {
                $seg1[$j] = $i;
                $i = $clip1[$i];
            }
            $seg1[0] = 0;

            $pen[0]=0;
            for ($j=1; $j<=$m; $j++) {
                for ($i=$seg1[$j]; $i<=$seg0[$j]; $i++) {
                    $best = -1;
                    for ($k=$seg0[$j-1]; $k>=$clip1[$i]; $k--) {
                        $thispen = $penalty3($path, $k, $i) + $pen[$k];
                        if ($best < 0 || $thispen < $best) {
                            $prev[$i] = $k;
                            $best = $thispen;
                        }
                    }
                    $pen[$i] = $best;
                }
            }
            $path->m = $m;
            $path->po = array_fill(0, $m, NULL);

            for ($i=$n, $j=$m-1; $i>0; $j--) {
                $i = $prev[$i];
                $path->po[$j] = $i;
            }
        };

        $adjustVertices = function (&$path) use($mod, $quadform){

            $pointslope = function ($path, $i, $j, &$ctr, &$dir) {

                $n = $path->len; $sums = $path->sums;
                $r=0;

                while ($j>=$n) {
                    $j-=$n;
                    $r+=1;
                }
                while ($i>=$n) {
                    $i-=$n;
                    $r-=1;
                }
                while ($j<0) {
                    $j+=$n;
                    $r-=1;
                }
                while ($i<0) {
                    $i+=$n;
                    $r+=1;
                }

                $x = $sums[$j+1]->x - $sums[$i]->x + $r * $sums[$n]->x;
                $y = $sums[$j+1]->y - $sums[$i]->y + $r * $sums[$n]->y;
                $x2 = $sums[$j+1]->x2 - $sums[$i]->x2 + $r * $sums[$n]->x2;
                $xy = $sums[$j+1]->xy - $sums[$i]->xy + $r * $sums[$n]->xy;
                $y2 = $sums[$j+1]->y2 - $sums[$i]->y2 + $r * $sums[$n]->y2;
                $k = $j+1-$i+$r*$n;

                $ctr->x = $x/$k;
                $ctr->y = $y/$k;

                $a = ($x2-$x*$x/$k)/$k;
                $b = ($xy-$x*$y/$k)/$k;
                $c = ($y2-$y*$y/$k)/$k;

                $lambda2 = ($a + $c+ sqrt(($a - $c)*($a - $c) + 4 * $b * $b))/2;

                $a -= $lambda2;
                $c -= $lambda2;

                if (abs($a) >= abs($c)) {
                    $l = sqrt($a*$a+$b*$b);
                    if ($l!=0) {
                        $dir->x = -$b/$l;
                        $dir->y = $a/$l;
                    }
                } else {
                    $l = sqrt($c*$c+$b*$b);
                    if ($l!==0) {
                        $dir->x = -$c/$l;
                        $dir->y = $b/$l;
                    }
                }
                if ($l==0) {
                    $dir->x = $dir->y = 0;
                }
            };

            $m = $path->m; $po = $path->po; $n = $path->len; $pt = $path->pt;
            $x0 = $path->x0; $y0 = $path->y0;
            $ctr = array_fill(0, $m, NULL); $dir = array_fill(0, $m, NULL);
            $q = array_fill(0, $m, NULL);
            $v = array_fill(0, 3, NULL);
            $s = new Point();

            $path->curve = new Curve($m);

            for ($i=0; $i<$m; $i++) {
                $j = $po[$mod($i+1,$m)];
                $j = $mod($j-$po[$i],$n)+$po[$i];
                $ctr[$i] = new Point();
                $dir[$i] = new Point();
                $pointslope($path, $po[$i], $j, $ctr[$i], $dir[$i]);
            }

            for ($i=0; $i<$m; $i++) {
                $q[$i] = new Quad();
                $d = $dir[$i]->x * $dir[$i]->x + $dir[$i]->y * $dir[$i]->y;
                if ($d == 0.0) {
                    for ($j=0; $j<3; $j++) {
                        for ($k=0; $k<3; $k++) {
                            $q[$i]->data[$j * 3 + $k] = 0;
                        }
                    }
                } else {
                    $v[0] = $dir[$i]->y;
                    $v[1] = -$dir[$i]->x;
                    $v[2] = - $v[1] * $ctr[$i]->y - $v[0] * $ctr[$i]->x;
                    for ($l=0; $l<3; $l++) {
                        for ($k=0; $k<3; $k++) {
                            if($d != 0){
                                $q[$i]->data[$l * 3 + $k] = $v[$l] * $v[$k] / $d;
                            }else{
                                $q[$i]->data[$l * 3 + $k] = INF; // TODO Hack para evitar división por 0
                            }
                        }
                    }
                }
            }

            for ($i=0; $i<$m; $i++) {
                $Q = new Quad();
                $w = new Point();

                $s->x = $pt[$po[$i]]->x - $x0;
                $s->y = $pt[$po[$i]]->y - $y0;

                $j = $mod($i-1,$m);

                for ($l=0; $l<3; $l++) {
                    for ($k=0; $k<3; $k++) {
                        $Q->data[$l * 3 + $k] = $q[$j]->at($l, $k) + $q[$i]->at($l, $k);
                    }
                }

                while(1) {

                    $det = $Q->at(0, 0)*$Q->at(1, 1) - $Q->at(0, 1)*$Q->at(1, 0);
                    if ($det != 0) {
                        $w->x = (-$Q->at(0, 2)*$Q->at(1, 1) + $Q->at(1, 2)*$Q->at(0, 1)) / $det;
                        $w->y = ( $Q->at(0, 2)*$Q->at(1, 0) - $Q->at(1, 2)*$Q->at(0, 0)) / $det;
                        break;
                    }

                    if ($Q->at(0, 0)>$Q->at(1, 1)) {
                        $v[0] = -$Q->at(0, 1);
                        $v[1] = $Q->at(0, 0);
                    } else if ($Q->at(1, 1)) {
                        $v[0] = -$Q->at(1, 1);
                        $v[1] = $Q->at(1, 0);
                    } else {
                        $v[0] = 1;
                        $v[1] = 0;
                    }
                    $d = $v[0] * $v[0] + $v[1] * $v[1];
                    $v[2] = - $v[1] * $s->y - $v[0] * $s->x;
                    for ($l=0; $l<3; $l++) {
                        for ($k=0; $k<3; $k++) {
                            $Q->data[$l * 3 + $k] += $v[$l] * $v[$k] / $d;
                        }
                    }
                }
                $dx = abs($w->x-$s->x);
                $dy = abs($w->y-$s->y);
                if ($dx <= 0.5 && $dy <= 0.5) {
                    $path->curve->vertex[$i] = new Point($w->x+$x0, $w->y+$y0);
                    continue;
                }

                $min = $quadform($Q, $s);
                $xmin = $s->x;
                $ymin = $s->y;

                if ($Q->at(0, 0) != 0.0) {
                    for ($z=0; $z<2; $z++) {
                        $w->y = $s->y-0.5+$z;
                        $w->x = - ($Q->at(0, 1) * $w->y + $Q->at(0, 2)) / $Q->at(0, 0);
                        $dx = abs($w->x-$s->x);
                        $cand = $quadform($Q, $w);
                        if ($dx <= 0.5 && $cand < $min) {
                            $min = $cand;
                            $xmin = $w->x;
                            $ymin = $w->y;
                        }
                    }
                }

                if ($Q->at(1, 1) != 0.0) {
                    for ($z=0; $z<2; $z++) {
                        $w->x = $s->x-0.5+$z;
                        $w->y = - ($Q->at(1, 0) * $w->x + $Q->at(1, 2)) / $Q->at(1, 1);
                        $dy = abs($w->y-$s->y);
                        $cand = $quadform($Q, $w);
                        if ($dy <= 0.5 && $cand < $min) {
                            $min = $cand;
                            $xmin = $w->x;
                            $ymin = $w->y;
                        }
                    }
                }

                for ($l=0; $l<2; $l++) {
                    for ($k=0; $k<2; $k++) {
                        $w->x = $s->x-0.5+$l;
                        $w->y = $s->y-0.5+$k;
                        $cand = $quadform($Q, $w);
                        if ($cand < $min) {
                            $min = $cand;
                            $xmin = $w->x;
                            $ymin = $w->y;
                        }
                    }
                }

                $path->curve->vertex[$i] = new Point($xmin + $x0, $ymin + $y0);
            }
        };

        $reverse = function (&$path) {
            $curve = &$path->curve; $m = &$curve->n; $v = &$curve->vertex;

            for ($i=0, $j=$m-1; $i<$j; $i++, $j--) {
                $tmp = $v[$i];
                $v[$i] = $v[$j];
                $v[$j] = $tmp;
            }
        };

        $smooth = function (&$path) use($mod, $interval, $ddenom, $dpara, $info){
            $m = $path->curve->n; $curve = &$path->curve;

            for ($i=0; $i<$m; $i++) {
                $j = $mod($i+1, $m);
                $k = $mod($i+2, $m);
                $p4 = $interval(1/2.0, $curve->vertex[$k], $curve->vertex[$j]);

                $denom = $ddenom($curve->vertex[$i], $curve->vertex[$k]);
                if ($denom != 0.0) {
                    $dd = $dpara($curve->vertex[$i], $curve->vertex[$j], $curve->vertex[$k]) / $denom;
                    $dd = abs($dd);
                    $alpha = $dd>1 ? (1 - 1.0/$dd) : 0;
                    $alpha = $alpha / 0.75;
                } else {
                    $alpha = 4/3.0;
                }
                $curve->alpha0[$j] = $alpha;

                if ($alpha >= $info->alphamax) {
                    $curve->tag[$j] = "CORNER";
                    $curve->c[3 * $j + 1] = $curve->vertex[$j];
                    $curve->c[3 * $j + 2] = $p4;
                } else {
                    if ($alpha < 0.55) {
                        $alpha = 0.55;
                    } else if ($alpha > 1) {
                        $alpha = 1;
                    }
                    $p2 = $interval(0.5+0.5*$alpha, $curve->vertex[$i], $curve->vertex[$j]);
                    $p3 = $interval(0.5+0.5*$alpha, $curve->vertex[$k], $curve->vertex[$j]);
                    $curve->tag[$j] = "CURVE";
                    $curve->c[3 * $j + 0] = $p2;
                    $curve->c[3 * $j + 1] = $p3;
                    $curve->c[3 * $j + 2] = $p4;
                }
                $curve->alpha[$j] = $alpha;
                $curve->beta[$j] = 0.5;
            }
            $curve->alphacurve = 1;
        };

        $optiCurve = function (&$path) use($mod, $ddist, $sign, $cprod, $dpara, $interval, $tangent, $bezier, $iprod, $iprod1, $info){
            $opti_penalty = function ($path, $i, $j, $res, $opttolerance, $convc, $areac) use($mod, $ddist, $sign, $cprod, $dpara, $interval, $tangent, $bezier, $iprod, $iprod1){
                $m = $path->curve->n; $curve = $path->curve; $vertex = $curve->vertex;
                if ($i==$j) {
                    return 1;
                }

                $k = $i;
                $i1 = $mod($i+1, $m);
                $k1 = $mod($k+1, $m);
                $conv = $convc[$k1];
                if ($conv == 0) {
                    return 1;
                }
                $d = $ddist($vertex[$i], $vertex[$i1]);
                for ($k=$k1; $k!=$j; $k=$k1) {
                    $k1 = $mod($k+1, $m);
                    $k2 = $mod($k+2, $m);
                    if ($convc[$k1] != $conv) {
                        return 1;
                    }
                    if ($sign($cprod($vertex[$i], $vertex[$i1], $vertex[$k1], $vertex[$k2])) !=
                        $conv) {
                            return 1;
                        }
                        if ($iprod1($vertex[$i], $vertex[$i1], $vertex[$k1], $vertex[$k2]) <
                            $d * $ddist($vertex[$k1], $vertex[$k2]) * -0.999847695156) {
                                return 1;
                            }
                }

                $p0 = clone $curve->c[$mod($i,$m) * 3 + 2];
                $p1 = clone $vertex[$mod($i+1,$m)];
                $p2 = clone $vertex[$mod($j,$m)];
                $p3 = clone $curve->c[$mod($j,$m) * 3 + 2];

                $area = $areac[$j] - $areac[$i];
                $area -= $dpara($vertex[0], $curve->c[$i * 3 + 2], $curve->c[$j * 3 + 2])/2;
                if ($i>=$j) {
                    $area += $areac[$m];
                }

                $A1 = $dpara($p0, $p1, $p2);
                $A2 = $dpara($p0, $p1, $p3);
                $A3 = $dpara($p0, $p2, $p3);

                $A4 = $A1+$A3-$A2;

                if ($A2 == $A1) {
                    return 1;
                }

                $t = $A3/($A3-$A4);
                $s = $A2/($A2-$A1);
                $A = $A2 * $t / 2.0;

                if ($A == 0.0) {
                    return 1;
                }

                $R = $area / $A;
                $alpha = 2 - sqrt(4 - $R / 0.3);

                $res->c[0] = $interval($t * $alpha, $p0, $p1);
                $res->c[1] = $interval($s * $alpha, $p3, $p2);
                $res->alpha = $alpha;
                $res->t = $t;
                $res->s = $s;

                $p1 = clone $res->c[0];
                $p2 = clone $res->c[1];

                $res->pen = 0;

                for ($k=$mod($i+1,$m); $k!=$j; $k=$k1) {
                    $k1 = $mod($k+1,$m);
                    $t = $tangent($p0, $p1, $p2, $p3, $vertex[$k], $vertex[$k1]);
                    if ($t<-0.5) {
                        return 1;
                    }
                    $pt = $bezier($t, $p0, $p1, $p2, $p3);
                    $d = $ddist($vertex[$k], $vertex[$k1]);
                    if ($d == 0.0) {
                        return 1;
                    }
                    $d1 = $dpara($vertex[$k], $vertex[$k1], $pt) / $d;
                    if (abs($d1) > $opttolerance) {
                        return 1;
                    }
                    if ($iprod($vertex[$k], $vertex[$k1], $pt) < 0 ||
                        $iprod($vertex[$k1], $vertex[$k], $pt) < 0) {
                            return 1;
                        }
                        $res->pen += $d1 * $d1;
                }

                for ($k=$i; $k!=$j; $k=$k1) {
                    $k1 = $mod($k+1,$m);
                    $t = $tangent($p0, $p1, $p2, $p3, $curve->c[$k * 3 + 2], $curve->c[$k1 * 3 + 2]);
                    if ($t<-0.5) {
                        return 1;
                    }
                    $pt = $bezier($t, $p0, $p1, $p2, $p3);
                    $d = $ddist($curve->c[$k * 3 + 2], $curve->c[$k1 * 3 + 2]);
                    if ($d == 0.0) {
                        return 1;
                    }
                    $d1 = $dpara($curve->c[$k * 3 + 2], $curve->c[$k1 * 3 + 2], $pt) / $d;
                    $d2 = $dpara($curve->c[$k * 3 + 2], $curve->c[$k1 * 3 + 2], $vertex[$k1]) / $d;
                    $d2 *= 0.75 * $curve->alpha[$k1];
                    if ($d2 < 0) {
                        $d1 = -$d1;
                        $d2 = -$d2;
                    }
                    if ($d1 < $d2 - $opttolerance) {
                        return 1;
                    }
                    if ($d1 < $d2) {
                        $res->pen += ($d1 - $d2) * ($d1 - $d2);
                    }
                }

                return 0;
            };

            $curve = $path->curve; $m = $curve->n; $vert = $curve->vertex;
            $pt = array_fill(0, $m + 1, NULL);
            $pen = array_fill(0, $m + 1, NULL);
            $len = array_fill(0, $m + 1, NULL);
            $opt = array_fill(0, $m + 1, NULL);
            $o = new Opti();

            $convc = array_fill(0, $m, NULL); $areac = array_fill(0, $m + 1, NULL);

            for ($i=0; $i<$m; $i++) {
                if ($curve->tag[$i] == "CURVE") {
                    $convc[$i] = $sign($dpara($vert[$mod($i-1,$m)], $vert[$i], $vert[$mod($i+1,$m)]));
                } else {
                    $convc[$i] = 0;
                }
            }

            $area = 0.0;
            $areac[0] = 0.0;
            $p0 = $curve->vertex[0];
            for ($i=0; $i<$m; $i++) {
                $i1 = $mod($i+1, $m);
                if ($curve->tag[$i1] == "CURVE") {
                    $alpha = $curve->alpha[$i1];
                    $area += 0.3 * $alpha * (4-$alpha) *
                    $dpara($curve->c[$i * 3 + 2], $vert[$i1], $curve->c[$i1 * 3 + 2])/2;
                    $area += $dpara($p0, $curve->c[$i * 3 + 2], $curve->c[$i1 * 3 + 2])/2;
                }
                $areac[$i+1] = $area;
            }

            $pt[0] = -1;
            $pen[0] = 0;
            $len[0] = 0;


            for ($j=1; $j<=$m; $j++) {
                $pt[$j] = $j-1;
                $pen[$j] = $pen[$j-1];
                $len[$j] = $len[$j-1]+1;

                for ($i=$j-2; $i>=0; $i--) {
                    $r = $opti_penalty($path, $i, $mod($j,$m), $o, $info->opttolerance, $convc,
                        $areac);
                    if ($r) {
                        break;
                    }
                    if ($len[$j] > $len[$i]+1 ||
                        ($len[$j] == $len[$i]+1 && $pen[$j] > $pen[$i] + $o->pen)) {
                            $pt[$j] = $i;
                            $pen[$j] = $pen[$i] + $o->pen;
                            $len[$j] = $len[$i] + 1;
                            $opt[$j] = $o;
                            $o = new Opti();
                        }
                }
            }
            $om = $len[$m];
            $ocurve = new Curve($om);
            $s = array_fill(0, $om, NULL);
            $t = array_fill(0, $om, NULL);

            $j = $m;
            for ($i=$om-1; $i>=0; $i--) {
                if ($pt[$j]==$j-1) {
                    $ocurve->tag[$i]     = $curve->tag[$mod($j,$m)];
                    $ocurve->c[$i * 3 + 0]    = $curve->c[$mod($j,$m) * 3 + 0];
                    $ocurve->c[$i * 3 + 1]    = $curve->c[$mod($j,$m) * 3 + 1];
                    $ocurve->c[$i * 3 + 2]    = $curve->c[$mod($j,$m) * 3 + 2];
                    $ocurve->vertex[$i]  = $curve->vertex[$mod($j,$m)];
                    $ocurve->alpha[$i]   = $curve->alpha[$mod($j,$m)];
                    $ocurve->alpha0[$i]  = $curve->alpha0[$mod($j,$m)];
                    $ocurve->beta[$i]    = $curve->beta[$mod($j,$m)];
                    $s[$i] = $t[$i] = 1.0;
                } else {
                    $ocurve->tag[$i] = "CURVE";
                    $ocurve->c[$i * 3 + 0] = $opt[$j]->c[0];
                    $ocurve->c[$i * 3 + 1] = $opt[$j]->c[1];
                    $ocurve->c[$i * 3 + 2] = $curve->c[$mod($j,$m) * 3 + 2];
                    $ocurve->vertex[$i] = $interval($opt[$j]->s, $curve->c[$mod($j,$m) * 3 + 2],
                        $vert[$mod($j,$m)]);
                    $ocurve->alpha[$i] = $opt[$j]->alpha;
                    $ocurve->alpha0[$i] = $opt[$j]->alpha;
                    $s[$i] = $opt[$j]->s;
                    $t[$i] = $opt[$j]->t;
                }
                $j = $pt[$j];
            }

            for ($i=0; $i<$om; $i++) {
                $i1 = $mod($i+1,$om);
                if(($s[$i] + $t[$i1]) != 0){
                    $ocurve->beta[$i] = $s[$i] / ($s[$i] + $t[$i1]);
                }else{
                    $ocurve->beta[$i] = INF; // TODO Hack para evitar división por 0
                }
            }
            $ocurve->alphacurve = 1;
            $path->curve = $ocurve;
        };

        for ($i = 0; $i < count($this->pathlist); $i++) {
            $path = &$this->pathlist[$i];
            $calcSums($path);
            $calcLon($path);
            $bestPolygon($path);
            $adjustVertices($path);

            if ($path->sign === "-") {
                $reverse($path);
            }

            $smooth($path);

            if ($info->optcurve) {
                $optiCurve($path);
            }
        }
    }

}
/**
 * Data structure
 */
if (!class_exists('Point')){
    class Point{
        public $x;
        public $y;
        
        public function __construct($x=NULL, $y=NULL) {
            if($x !== NULL)
                $this->x = $x;
                if($y !== NULL)
                    $this->y = $y;
        }
    }
}
if (!class_exists('Opti')){
    class Opti{
        public $pen = 0;
        public $c;
        public $t = 0;
        public $s = 0;
        public $alpha = 0;
    
        public function __construct(){
            $this->c = array(new Point(), new Point());
        }
    }
}
if (!class_exists('Bitmap')){
    class Bitmap{
        public $w;
        public $h;
        public $size;
        public $data;
    
        public function __construct($w, $h){
            $this->w = $w;
            $this->h = $h;
            $this->size = $w * $h;
            $this->data = array();
        }
    
        public function at($x, $y) {
            return ($x >= 0 && $x < $this->w && $y >=0 && $y < $this->h) &&
            $this->data[$this->w * $y + $x] === 1;
        }
    
        public function index($i) {
            $point = new Point();
            $point->y = floor($i / $this->w);
            $point->x = $i - $point->y * $this->w;
            return $point;
        }
    
        public function flip($x, $y) {
            if ($this->at($x, $y)) {
                $this->data[$this->w * $y + $x] = 0;
            } else {
                $this->data[$this->w * $y + $x] = 1;
            }
        }
    }
}
if (!class_exists('Path')){
    class Path{
        public $area = 0;
        public $len = 0;
        public $curve = array();
        public $pt = array();
        public $minX = 100000;
        public $minY = 100000;
        public $maxX= -1;
        public $maxY = -1;
        public $sum = array();
        public $lon = array();
    }
}
if (!class_exists('Curve')){
    class Curve{
        public $n;
        public $tag;
        public $c;
        public $alphaCurve = 0;
        public $vertex;
        public $alpha;
        public $alpha0;
        public $beta;
    
        public function __construct($n){
            $this->n = $n;
            $this->tag = array_fill(0, $n, NULL);
            $this->c = array_fill(0, $n * 3, NULL);
            $this->vertex = array_fill(0, $n, NULL);
            $this->alpha = array_fill(0, $n, NULL);
            $this->alpha0 = array_fill(0, $n, NULL);
            $this->beta = array_fill(0, $n, NULL);
        }
    }
}
if (!class_exists('Quad')){
    class Quad{
        public $data = array(0,0,0,0,0,0,0,0,0);
    
        public function at($x, $y) {
            return $this->data[$x * 3 + $y];
        }
    }
}
if (!class_exists('Sum')){
    class Sum{
        public $x;
        public $y;
        public $xy;
        public $x2;
        public $y2;
    
        public function __construct($x, $y, $xy, $x2, $y2) {
            $this->x = $x;
            $this->y = $y;
            $this->xy = $xy;
            $this->x2 = $x2;
            $this->y2 = $y2;
        }
    }
}
?>