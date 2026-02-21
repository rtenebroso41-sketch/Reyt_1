<?php
// --- CONFIGURATION & ERROR REPORTING ---
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('memory_limit', '256M'); 

session_start();

// Create a unique folder for this session (KEPT)
if (!isset($_SESSION['user_folder'])) {
    $_SESSION['user_folder'] = 'session_' . uniqid();
}

$sessionFolder = $_SESSION['user_folder'];
$uploadDir = __DIR__ . '/uploads/' . $sessionFolder . '/'; 
$webDir = 'uploads/' . $sessionFolder . '/';              

if (!file_exists($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

$message = "";
$downloadTrigger = false;
$downloadFile = "";

// --- BACKEND LOGIC ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // 1. MANUAL CLEANUP (Triggered by Button Only)
    if (isset($_POST['action']) && $_POST['action'] === 'clean_session') {
        if (is_dir($uploadDir)) {
            $files = glob($uploadDir . '*');
            foreach($files as $file) {
                if(is_file($file)) unlink($file);
            }
            rmdir($uploadDir);
        }
        session_destroy();
        // Redirect to self to reset the session folder generation
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }

    // 2. UPLOAD
    if (isset($_POST['action']) && $_POST['action'] === 'upload' && isset($_FILES['image'])) {
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $uploadedCount = 0;
        
        $files = $_FILES['image'];

        if (!is_array($files['name'])) {
            $files = [
                'name'     => [$files['name']],
                'type'     => [$files['type']],
                'tmp_name' => [$files['tmp_name']],
                'error'    => [$files['error']],
                'size'     => [$files['size']]
            ];
        }

        $fileCount = count($files['name']);

        for ($i = 0; $i < $fileCount; $i++) {
            if ($files['error'][$i] === UPLOAD_ERR_OK && in_array($files['type'][$i], $allowedTypes)) {
                $ext = pathinfo($files['name'][$i], PATHINFO_EXTENSION);
                $newFilename = uniqid() . '.' . $ext;
                $targetPath = $uploadDir . $newFilename;
                $tmpName = $files['tmp_name'][$i];

                if (move_uploaded_file($tmpName, $targetPath)) {
                    copy($targetPath, $uploadDir . 'backup_' . $newFilename);
                    $uploadedCount++;
                }
            }
        }

        if ($uploadedCount > 0) {
            $message = "$uploadedCount image(s) uploaded successfully!";
        }
    }

    // 3. DELETE
    if (isset($_POST['action']) && $_POST['action'] === 'delete' && isset($_POST['filename'])) {
        $fileToDelete = $uploadDir . basename($_POST['filename']);
        $backupToDelete = $uploadDir . 'backup_' . basename($_POST['filename']);
        
        if (file_exists($fileToDelete)) unlink($fileToDelete);
        if (file_exists($backupToDelete)) unlink($backupToDelete);
        
        $message = "Image deleted.";
    }

    // 4. RESET
    if (isset($_POST['action']) && $_POST['action'] === 'reset' && isset($_POST['filename'])) {
        $filename = basename($_POST['filename']);
        $backupPath = $uploadDir . 'backup_' . $filename;
        $mainPath = $uploadDir . $filename;

        if (file_exists($backupPath)) {
            copy($backupPath, $mainPath);
            $message = "Image reset to original.";
        }
    }

    // 5. PROCESS (Edit & Auto-Download)
    if (isset($_POST['action']) && $_POST['action'] === 'process' && isset($_POST['filename'])) {
        $filename = basename($_POST['filename']);
        $filePath = $uploadDir . $filename;
        
        if (file_exists($filePath)) {
            if (!extension_loaded('gd')) {
                $message = "Error: GD Library not enabled.";
            } else {
                $imageInfo = @getimagesize($filePath);
                $mimeType = $imageInfo['mime'];
                $image = null;

                switch ($mimeType) {
                    case 'image/jpeg': if (function_exists('imagecreatefromjpeg')) $image = @imagecreatefromjpeg($filePath); break;
                    case 'image/png':  if (function_exists('imagecreatefrompng')) $image = @imagecreatefrompng($filePath); break;
                    case 'image/gif':  if (function_exists('imagecreatefromgif')) $image = @imagecreatefromgif($filePath); break;
                }

                if ($image) {
                    // Resize
                    if (!empty($_POST['resize_width']) && is_numeric($_POST['resize_width'])) {
                        $newWidth = intval($_POST['resize_width']);
                        $oldWidth = imagesx($image);
                        $oldHeight = imagesy($image);
                        if ($newWidth > 0 && $oldWidth > 0) {
                            $newHeight = round(($oldHeight / $oldWidth) * $newWidth);
                            $newImage = imagecreatetruecolor($newWidth, $newHeight);
                            if ($mimeType == 'image/png') {
                                imagealphablending($newImage, false);
                                imagesavealpha($newImage, true);
                                $transparent = imagecolorallocatealpha($newImage, 255,255,255, 127);
                                imagefilledrectangle($newImage, 0, 0, $newWidth, $newHeight, $transparent);
                            }
                            imagecopyresampled($newImage, $image, 0, 0, 0, 0, $newWidth, $newHeight, $oldWidth, $oldHeight);
                            imagedestroy($image);
                            $image = $newImage;
                        }
                    }

                    // Filters
                    if (isset($_POST['effect_grayscale'])) imagefilter($image, IMG_FILTER_GRAYSCALE);
                    if (isset($_POST['effect_invert'])) imagefilter($image, IMG_FILTER_NEGATE);
                    if (isset($_POST['effect_sepia'])) {
                        imagefilter($image, IMG_FILTER_GRAYSCALE);
                        imagefilter($image, IMG_FILTER_COLORIZE, 100, 50, 0);
                    }

                    // Watermark
                    if (!empty($_POST['watermark_text'])) {
                        $text = $_POST['watermark_text'];
                        $white = imagecolorallocate($image, 255, 255, 255);
                        $black = imagecolorallocate($image, 0, 0, 0);
                        $fontSize = 5; $x = 10; $y = imagesy($image) - 20;
                        if ($y < $fontSize) $y = $fontSize + 5;
                        imagestring($image, $fontSize, $x + 1, $y + 1, $text, $black);
                        imagestring($image, $fontSize, $x, $y, $text, $white);
                    }

                    // Save
                    $saved = false;
                    switch ($mimeType) {
                        case 'image/jpeg': $saved = imagejpeg($image, $filePath, 90); break;
                        case 'image/png':  $saved = imagepng($image, $filePath); break;
                        case 'image/gif':  $saved = imagegif($image, $filePath); break;
                    }
                    imagedestroy($image);
                    
                    if ($saved) {
                        $downloadTrigger = true;
                        $downloadFile = $filename;
                        $message = "Edit applied! Downloading image...";
                    }
                }
            }
        }
    }
}

// Handle Direct Download Request
if (isset($_GET['action']) && $_GET['action'] === 'download' && isset($_GET['filename'])) {
    $filename = basename($_GET['filename']);
    $filePath = $uploadDir . $filename;
    
    if (file_exists($filePath)) {
        header('Content-Description: File Transfer');
        header('Content-Type: ' . mime_content_type($filePath));
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($filePath));
        readfile($filePath);
        exit;
    }
}

$images = glob($uploadDir . '*.{jpg,jpeg,png,gif,webp}', GLOB_BRACE);
$images = array_filter($images, function($v) { return strpos(basename($v), 'backup_') === false; });

if ($images) {
    usort($images, function($a, $b) { return filemtime($b) - filemtime($a); });
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BlueStream · Image System</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* ===== BLUESTREAM DESIGN SYSTEM ===== */
        :root {
            --primary: #0066ff;
            --primary-dark: #0052cc;
            --primary-light: #4d94ff;
            --secondary: #00c2ff;
            --accent: #7b68ee;
            --success: #00b894;
            --warning: #fdcb6e;
            --danger: #ff4757;
            
            --bg-dark: #0a1a2f;
            --bg-card: #112240;
            --bg-input: #1a2f4f;
            --bg-side: #0e1d36;
            
            --text-primary: #ffffff;
            --text-secondary: #b0c4de;
            --text-muted: #6c8eb0;
            
            --border-light: #1e3a5f;
            --border-glow: rgba(0, 102, 255, 0.3);
            
            --shadow-sm: 0 4px 12px rgba(0, 0, 0, 0.2);
            --shadow-md: 0 8px 24px rgba(0, 102, 255, 0.15);
            --shadow-lg: 0 16px 40px rgba(0, 102, 255, 0.2);
            
            --gradient-blue: linear-gradient(135deg, #0066ff, #00c2ff);
            --gradient-purple: linear-gradient(135deg, #7b68ee, #0066ff);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--bg-dark);
            background-image: 
                radial-gradient(circle at 20% 30%, rgba(0, 102, 255, 0.08) 0%, transparent 30%),
                radial-gradient(circle at 80% 70%, rgba(0, 194, 255, 0.08) 0%, transparent 35%);
            color: var(--text-primary);
            min-height: 100vh;
            padding: 0;
            margin: 0;
            line-height: 1.6;
        }

        /* Main Layout */
        .app-container {
            display: flex;
            min-height: 100vh;
        }

        /* Sidebar */
        .sidebar {
            width: 280px;
            background: var(--bg-side);
            backdrop-filter: blur(10px);
            border-right: 1px solid var(--border-light);
            padding: 2rem 1.5rem;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            box-shadow: var(--shadow-sm);
        }

        .logo {
            margin-bottom: 3rem;
        }

        .logo-text {
            font-size: 1.8rem;
            font-weight: 700;
            background: var(--gradient-blue);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            letter-spacing: -0.5px;
        }

        .logo-sub {
            color: var(--text-muted);
            font-size: 0.85rem;
            margin-top: 0.25rem;
        }

        .nav-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
            color: var(--text-secondary);
            text-decoration: none;
            border-radius: 12px;
            margin-bottom: 8px;
            transition: all 0.3s ease;
            border: 1px solid transparent;
        }

        .nav-item:hover {
            background: rgba(0, 102, 255, 0.1);
            border-color: var(--border-light);
            color: var(--text-primary);
        }

        .nav-item.active {
            background: var(--gradient-blue);
            color: white;
            box-shadow: var(--shadow-md);
        }

        .nav-icon {
            font-size: 1.2rem;
        }

        /* Main Content */
        .main-content {
            flex: 1;
            margin-left: 280px;
            padding: 2rem;
        }

        /* Header */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            background: var(--bg-card);
            padding: 1.5rem 2rem;
            border-radius: 20px;
            border: 1px solid var(--border-light);
            box-shadow: var(--shadow-sm);
        }

        .page-title h1 {
            font-size: 2rem;
            font-weight: 600;
            background: var(--gradient-blue);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .page-title p {
            color: var(--text-secondary);
            font-size: 0.9rem;
        }

        .stats-badge {
            background: var(--bg-input);
            padding: 0.5rem 1.2rem;
            border-radius: 40px;
            border: 1px solid var(--border-light);
            color: var(--text-secondary);
            font-size: 0.9rem;
        }

        .stats-badge span {
            color: var(--primary-light);
            font-weight: 700;
            margin-left: 5px;
        }

        /* Alert */
        .alert {
            background: rgba(0, 102, 255, 0.15);
            border: 1px solid var(--primary);
            color: var(--primary-light);
            padding: 1.2rem 1.8rem;
            border-radius: 16px;
            margin-bottom: 2rem;
            font-weight: 500;
            backdrop-filter: blur(10px);
            animation: slideIn 0.3s ease;
        }

        /* Upload Area */
        .upload-area {
            background: var(--bg-card);
            border: 2px dashed var(--border-light);
            border-radius: 30px;
            padding: 3rem 2rem;
            text-align: center;
            margin-bottom: 3rem;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .upload-area:hover {
            border-color: var(--primary);
            box-shadow: var(--shadow-md);
        }

        .upload-icon {
            font-size: 4rem;
            margin-bottom: 1rem;
            animation: float 3s ease-in-out infinite;
        }

        .upload-title {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }

        .upload-desc {
            color: var(--text-secondary);
            margin-bottom: 2rem;
        }

        /* Buttons */
        .btn {
            display: inline-block;
            padding: 12px 28px;
            border-radius: 50px;
            font-family: 'Inter', sans-serif;
            font-weight: 600;
            font-size: 0.95rem;
            cursor: pointer;
            transition: all 0.3s ease;
            border: none;
            text-decoration: none;
            letter-spacing: 0.3px;
        }

        .btn-primary {
            background: var(--gradient-blue);
            color: white;
            box-shadow: 0 4px 15px rgba(0, 102, 255, 0.3);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 102, 255, 0.4);
        }

        .btn-outline {
            background: transparent;
            border: 2px solid var(--border-light);
            color: var(--text-primary);
        }

        .btn-outline:hover {
            border-color: var(--primary);
            color: var(--primary-light);
            background: rgba(0, 102, 255, 0.1);
        }

        .btn-danger {
            background: linear-gradient(135deg, #ff4757, #ff6b81);
            color: white;
        }

        .btn-warning {
            background: linear-gradient(135deg, #fdcb6e, #ffe083);
            color: var(--bg-dark);
        }

        .btn-sm {
            padding: 8px 16px;
            font-size: 0.85rem;
        }

        .btn-clear {
            background: rgba(255, 255, 255, 0.05);
            color: var(--text-secondary);
            border: 1px solid var(--border-light);
        }

        .btn-clear:hover {
            background: var(--danger);
            color: white;
            border-color: var(--danger);
        }

        /* Gallery Grid */
        .gallery-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
            gap: 1.8rem;
        }

        /* Cards */
        .card {
            background: var(--bg-card);
            border: 1px solid var(--border-light);
            border-radius: 24px;
            overflow: hidden;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: var(--shadow-sm);
        }

        .card:hover {
            transform: translateY(-8px);
            border-color: var(--primary);
            box-shadow: var(--shadow-lg);
        }

        .card-image {
            height: 220px;
            overflow: hidden;
            position: relative;
            background: var(--bg-dark);
            cursor: pointer;
        }

        .card-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.8s ease;
            opacity: 0.9;
        }

        .card:hover .card-image img {
            transform: scale(1.1);
            opacity: 1;
        }

        .card-content {
            padding: 1.5rem;
        }

        .card-badge {
            display: inline-block;
            padding: 4px 12px;
            background: rgba(0, 102, 255, 0.15);
            border: 1px solid var(--primary);
            border-radius: 30px;
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--primary-light);
            margin-bottom: 1rem;
        }

        /* Editor Panel */
        .editor-panel {
            display: none;
            background: var(--bg-input);
            border-radius: 16px;
            padding: 1.5rem;
            margin-top: 1rem;
            border: 1px solid var(--border-light);
        }

        .editor-panel.active {
            display: block;
            animation: slideDown 0.3s ease;
        }

        .form-group {
            margin-bottom: 1.2rem;
        }

        .form-label {
            display: block;
            font-size: 0.8rem;
            color: var(--text-secondary);
            margin-bottom: 6px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .form-control {
            width: 100%;
            padding: 10px 14px;
            background: var(--bg-dark);
            border: 1px solid var(--border-light);
            border-radius: 12px;
            color: var(--text-primary);
            font-family: 'Inter', sans-serif;
            transition: all 0.3s;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px var(--border-glow);
        }

        .checkbox-group {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .checkbox-label {
            display: flex;
            align-items: center;
            gap: 6px;
            color: var(--text-secondary);
            cursor: pointer;
        }

        .checkbox-label input[type="checkbox"] {
            accent-color: var(--primary);
            width: 18px;
            height: 18px;
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(10, 26, 47, 0.95);
            backdrop-filter: blur(20px);
            justify-content: center;
            align-items: center;
            z-index: 1000;
        }

        .modal.show {
            display: flex;
        }

        .modal-content {
            max-width: 90%;
            max-height: 90%;
            border-radius: 20px;
            border: 2px solid var(--primary);
            box-shadow: var(--shadow-lg);
        }

        .modal-close {
            position: absolute;
            top: 30px;
            right: 40px;
            color: white;
            font-size: 40px;
            cursor: pointer;
            transition: 0.3s;
        }

        .modal-close:hover {
            color: var(--danger);
            transform: rotate(90deg);
        }

        /* Empty State */
        .empty-state {
            grid-column: 1 / -1;
            text-align: center;
            padding: 5rem 2rem;
            background: var(--bg-card);
            border-radius: 30px;
            border: 1px solid var(--border-light);
        }

        .empty-icon {
            font-size: 5rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        .empty-title {
            font-size: 1.8rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }

        .empty-desc {
            color: var(--text-secondary);
        }

        /* Animations */
        @keyframes float {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-10px); }
        }

        @keyframes slideIn {
            from { opacity: 0; transform: translateX(-20px); }
            to { opacity: 1; transform: translateX(0); }
        }

        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .sidebar {
                width: 80px;
                padding: 1rem 0.5rem;
            }
            .logo-text, .logo-sub, .nav-item span {
                display: none;
            }
            .nav-item {
                justify-content: center;
                padding: 12px;
            }
            .nav-icon {
                font-size: 1.5rem;
            }
            .main-content {
                margin-left: 80px;
            }
        }

        @media (max-width: 768px) {
            .app-container {
                flex-direction: column;
            }
            .sidebar {
                width: 100%;
                height: auto;
                position: relative;
                padding: 1rem;
            }
            .main-content {
                margin-left: 0;
            }
            .page-header {
                flex-direction: column;
                gap: 1rem;
                text-align: center;
            }
        }
    </style>
</head>
<body>
    <div class="app-container">
        <!-- Sidebar -->
        <div class="sidebar">
            <div class="logo">
                <div class="logo-text">BLUESTREAM</div>
                <div class="logo-sub">Image System</div>
            </div>
            
            <div class="nav-item active">
                <span class="nav-icon">📸</span>
                <span>Gallery</span>
            </div>
            <div class="nav-item">
                <span class="nav-icon">⬆️</span>
                <span>Upload</span>
            </div>
            <div class="nav-item">
                <span class="nav-icon">⚙️</span>
                <span>Settings</span>
            </div>
            <div class="nav-item">
                <span class="nav-icon">📊</span>
                <span>Analytics</span>
            </div>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <!-- Header -->
            <div class="page-header">
                <div class="page-title">
                    <h1>BlueStream Gallery</h1>
                    <p>Manage your images with style</p>
                </div>
                <div class="stats-badge">
                    Total Images <span><?php echo count($images); ?></span>
                </div>
            </div>

            <?php if ($message): ?>
                <div class="alert"><?php echo $message; ?></div>
            <?php endif; ?>

            <!-- Upload Area -->
            <div class="upload-area">
                <div class="upload-icon">☁️</div>
                <div class="upload-title">Upload to the Stream</div>
                <div class="upload-desc">Drag & drop or click to select images</div>
                
                <form action="" method="post" enctype="multipart/form-data" id="uploadForm">
                    <input type="hidden" name="action" value="upload">
                    <input type="file" name="image[]" accept="image/*" required id="fileInput" style="display:none" multiple>
                    <label for="fileInput" class="btn btn-outline" style="margin-right: 15px;">Select Images</label>
                    <span id="fileName" style="color: var(--primary-light); font-weight: 500;"></span>
                    <br><br>
                    <button type="submit" class="btn btn-primary">Upload to Stream</button>
                    
                    <?php if (!empty($images)): ?>
                        <button type="button" class="btn btn-clear" onclick="clearAllData()" style="margin-left: 15px;">Clear All</button>
                    <?php endif; ?>
                </form>
            </div>

            <!-- Gallery Grid -->
            <div class="gallery-grid">
                <?php foreach ($images as $path): 
                    $filename = basename($path);
                    $webPath = $webDir . $filename;
                    $webPathTs = $webPath . '?t=' . filemtime($path);
                ?>
                <div class="card">
                    <div class="card-image" onclick="openModal('<?php echo $webPath; ?>')">
                        <img src="<?php echo $webPathTs; ?>" alt="Image">
                    </div>
                    
                    <div class="card-content">
                        <div class="card-badge"><?php echo substr($filename, 0, 15); ?>...</div>
                        
                        <div style="display: flex; gap: 10px; margin-bottom: 10px;">
                            <button class="btn btn-sm btn-outline" onclick="toggleEditor('<?php echo $filename; ?>')" style="flex:1;">Edit</button>
                            <form method="post" onsubmit="return confirm('Delete this image?');" style="flex:1;">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="filename" value="<?php echo $filename; ?>">
                                <button type="submit" class="btn btn-sm btn-danger" style="width:100%;">Delete</button>
                            </form>
                        </div>

                        <!-- Editor Panel -->
                        <div id="editor-<?php echo $filename; ?>" class="editor-panel">
                            <form action="" method="post">
                                <input type="hidden" name="action" value="process">
                                <input type="hidden" name="filename" value="<?php echo $filename; ?>">
                                
                                <div class="form-group">
                                    <label class="form-label">Filters</label>
                                    <div class="checkbox-group">
                                        <label class="checkbox-label"><input type="checkbox" name="effect_grayscale"> Grayscale</label>
                                        <label class="checkbox-label"><input type="checkbox" name="effect_invert"> Invert</label>
                                        <label class="checkbox-label"><input type="checkbox" name="effect_sepia"> Sepia</label>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Resize Width (px)</label>
                                    <input type="number" name="resize_width" placeholder="e.g. 800" class="form-control">
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Watermark Text</label>
                                    <input type="text" name="watermark_text" placeholder="Type here..." class="form-control">
                                </div>
                                <button type="submit" class="btn btn-sm btn-primary" style="width: 100%; margin-bottom: 10px;">Apply & Download</button>
                            </form>

                            <form action="" method="post">
                                <input type="hidden" name="action" value="reset">
                                <input type="hidden" name="filename" value="<?php echo $filename; ?>">
                                <button type="submit" class="btn btn-sm btn-warning" style="width: 100%;">Reset Image</button>
                            </form>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
                
                <?php if (empty($images)): ?>
                    <div class="empty-state">
                        <div class="empty-icon">🌊</div>
                        <div class="empty-title">No Images in Stream</div>
                        <div class="empty-desc">Upload your first image to begin</div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Modal -->
    <div id="imageModal" class="modal">
        <span class="modal-close" onclick="closeModal()">&times;</span>
        <img class="modal-content" id="modalImg">
    </div>

    <!-- Hidden Form for Clear All -->
    <form id="clearForm" method="post">
        <input type="hidden" name="action" value="clean_session">
    </form>

    <script>
        // File Input Display
        document.getElementById('fileInput')?.addEventListener('change', function(e) {
            var files = e.target.files;
            var names = [];
            for (var i = 0; i < files.length; i++) names.push(files[i].name);
            if (names.length > 0) document.getElementById('fileName').textContent = names.join(', ');
        });

        // Toggle Editor
        function toggleEditor(filename) {
            var panel = document.getElementById('editor-' + filename);
            panel.classList.toggle('active');
        }

        // Modal
        function openModal(src) {
            var modal = document.getElementById("imageModal");
            var modalImg = document.getElementById("modalImg");
            modal.classList.add('show');
            modalImg.src = src.split('?')[0];
        }

        function closeModal() {
            var modal = document.getElementById("imageModal");
            modal.classList.remove('show');
        }

        window.onclick = function(event) {
            var modal = document.getElementById("imageModal");
            if (event.target == modal) closeModal();
        }

        // Clear All
        function clearAllData() {
            if(confirm('WARNING: This will permanently delete ALL uploaded images. Are you sure?')) {
                document.getElementById('clearForm').submit();
            }
        }

        // Auto Download
        <?php if ($downloadTrigger && !empty($downloadFile)): ?>
            window.onload = function() {
                var downloadLink = document.createElement('a');
                downloadLink.href = '?action=download&filename=<?php echo urlencode($downloadFile); ?>';
                downloadLink.style.display = 'none';
                document.body.appendChild(downloadLink);
                downloadLink.click();
                document.body.removeChild(downloadLink);
            };
        <?php endif; ?>
    </script>
</body>
</html>