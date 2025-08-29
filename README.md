# Image Vectorizer

A PHP-based color bitmap vectorization library that extends the [Potrace](https://potrace.sourceforge.net/) project by implementing KMeans++ algorithm for color clustering, enabling color image vectorization.

PHP >= 5.4

## Overview

Image Vectorizer is a powerful PHP class that converts raster images (PNG, JPG, Webp) into scalable vector graphics (SVG) using color clustering algorithms. Built upon the foundation of the Potrace project, it enhances the original black-and-white vectorization capabilities with sophisticated color handling through KMeans++ clustering.

## Features

- **Color Image Vectorization**: Transform color bitmaps into clean, scalable SVG files
- **KMeans++ Algorithm**: Color clustering for accurate color separation
- **Configurable Parameters**: Fine-tune color detection, noise reduction, and clustering behavior
- **Background Color Detection**: Automatic background identification and handling
- **Noise Reduction**: Intelligent filtering of minor color variations and artifacts
- **Multiple Color Support**: Configurable color palette size
- **Lab Color Space**: Optional CIELAB color space for more accurate color clustering

## Live Demo

This library is actively used in production at [Sologo.ai](https://www.sologo.ai/), a professional logo vectorization service. Visit the website to see the vectorization quality and capabilities in action.

## Quick Start

### Basic Usage

```php
<?php
require_once 'ImageVectorizer.php';

// Initialize the vectorizer
$image_vectorizer = new ImageVectorizer();

// Configure vectorization parameters
$color_size = 3; // Number of colors in output
$config = array(
    'mode' => 'color',
    'color_size' => $color_size,
    'kmeans_difference_distance' => 5,
    'kmeans_use_lab_color' => true,
    'kmeans_gap_fix_value' => 5,
    'keep_color_rate_min' => 1,
    'similar_color_distance' => 40, // Similar color distance 0-255, smaller = more precise
    'bg_color_distance' => 35,
    'merge_color_hue_min' => 5,
    'remove_background' => false,
);

$image_vectorizer->setConfig($config);

// Process image
$input_image = 'test.png';
$output_image = 'test.svg';

$image_vectorizer->loadImageFromFile($input_image);
$svg = $image_vectorizer->getSVG();
file_put_contents($output_image, $svg);

echo 'Vectorization complete!';
?>
```

## Configuration Options

| Parameter | Description | Default | Range |
|-----------|-------------|---------|--------|
| `mode` | Processing mode: black for black & white, color for color vectorization | 'color' | 'color' / 'black' |
| `turnpolicy` | Path turning policy: controls how paths turn at corners | 'minority' | 'black' / 'white' / 'left' / 'right' / 'minority' / 'majority' |
| `turdsize` | Noise removal size in pixels: eliminates small artifacts | 2 | 0-100 |
| `optcurve` | Enable curve optimization for smoother paths and smaller file size | TRUE | true / false |
| `alphamax` | Corner threshold: higher values produce smoother curves but may distort details | 1 | 0-1.33 |
| `opttolerance` | Curve optimization tolerance: higher values = more aggressive optimization | 0.2 | 0-1 |
| `bitmap_type` | Bitmap generation method: determines how intermediate bitmap is created | 'balance' | 'blacklevel' / 'bgcolor' / 'balance' |
| `black_level` | Brightness threshold for black/white separation when bitmap_type='blacklevel' | 128 | 0-255 |
| `bgcolor_level` | Foreground/background contrast threshold when bitmap_type='bgcolor' | 120 | 0-441 |
| `balance_level` | Balance threshold for mixed content when bitmap_type='balance' | 128 | 0-441 |
| `clusters_num` | Maximum clusters for KMeans++ algorithm (should be ≥ color_size) | 10 | 5-20 |
| `clusters_num_max` | Upper bound for automatic cluster detection | 20 | 10-50 |
| `clusters_num_min` | Lower bound for automatic cluster detection | 5 | 1-10 |
| `color_size` | Number of colors in final output vector | 5 | 1-16 |
| `kmeans_difference_distance` | KMeans iteration convergence threshold: smaller values = more precise but slower | 5 | 1-20 |
| `kmeans_gap_fix_value` | Gap repair value: positive values fill gaps, negative values create separation | 5 | -10 to 20 |
| `kmeans_use_lab_color` | Use CIELAB color space instead of RGB for more accurate color clustering | true | true / false |
| `keep_color_rate_min` | Minimum color area percentage to retain: removes colors smaller than this threshold | 0.5 | 0-100 |
| `similar_color_distance` | Color similarity threshold for merging similar colors | 40 | 0-441 |
| `bg_color_distance` | Distance threshold from background color when extracting foreground colors | 35 | 0-441 |
| `merge_color_hue_min` | Minimum hue difference required for color merging | 5 | 0-30 |
| `merge_color_hue` | Enable hue-based color merging for similar colors | true | true / false |
| `remove_background` | Remove detected background from final output | false | true / false |

## Examples

### Input Bitmap
![Input Bitmap](test.png)

### Output Vector
![Output Vector](test.svg)

## Requirements

- PHP 5.4 or higher
- GD Library extension
- Fileinfo extension

## Installation

1. Download the `ImageVectorizer.php` file
2. Include it in your PHP project: `require_once 'ImageVectorizer.php'`
3. Start vectorizing images using the provided examples

## License

This project is open source and available under the MIT License. See the LICENSE file for details.

## Contributing

Contributions are welcome! Please feel free to submit issues, feature requests, or pull requests to improve the vectorization algorithms and functionality.

## Acknowledgments

- [Potrace](https://potrace.sourceforge.net/) - The original bitmap vectorization library
- KMeans++ algorithm for color clustering