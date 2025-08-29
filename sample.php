
<?php
require_once 'ImageVectorizer.php';
$image_vectorizer = new ImageVectorizer();

$color_size = 3;
$config = array(
            'mode'=>'color',
            'color_size'    =>$color_size,
            'kmeans_difference_distance'=> 5,
            'kmeans_use_lab_color'=>true,
            'kmeans_gap_fix_value'=>5,
            'keep_color_rate_min' => 1,
            'similar_color_distance' => 40, //合并用：相似色距离 0-255，越小越精确
            'bg_color_distance' => 35, //0-441
            'merge_color_hue_min' => 5,
            'remove_background'=>false,
        );
$image_vectorizer->setConfig($config);

$input_image = 'test.png';
$output_image = 'test.svg';

$image_vectorizer->loadImageFromFile($input_image);
$svg = $image_vectorizer->getSVG();
file_put_contents($output_image, $svg);
echo 'done';
?>