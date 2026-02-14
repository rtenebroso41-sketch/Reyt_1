<?php
// --- CONFIGURATION & ERROR REPORTING ---
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('memory_limit', '256M'); 

session_start();

// Create a unique folder for this session
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
    
    // 1. CLEANUP
    if (isset($_POST['action']) && $_POST['action'] === 'clean_session') {
        if (is_dir($uploadDir)) {
            $files = glob($uploadDir . '*');
            foreach($files as $file) {
                if(is_file($file)) unlink($file);
            }
            rmdir($uploadDir);
        }
        session_destroy();
        exit('Session Cleared');
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

                    // Save to server
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
    <title>Dreams Gallery</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-dark: #0f172a;
            --glass: rgba(255, 255, 255, 0.05);
            --glass-border: rgba(255, 255, 255, 0.1);
            --accent: #00f2ea;
            --accent-pink: #ff0055;
            --accent-purple: #7000ff;
            --text-main: #ffffff;
            --text-muted: #94a3b8;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Poppins', sans-serif;
            background-color: var(--bg-dark);
            background-image: 
                radial-gradient(circle at 10% 20%, rgba(112, 0, 255, 0.15) 0%, transparent 40%),
                radial-gradient(circle at 90% 80%, rgba(0, 242, 234, 0.15) 0%, transparent 40%);
            color: var(--text-main);
            min-height: 100vh;
            padding: 20px;
            overflow-x: hidden;
        }

        .container { max-width: 1400px; margin: 0 auto; position: relative; z-index: 1; }

        /* HEADER */
        header { text-align: center; padding: 4rem 0 2rem; }
        
        .logo {
            font-size: 3.5rem;
            font-weight: 700;
            background: linear-gradient(135deg, #fff 0%, var(--accent) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            text-shadow: 0 0 30px rgba(0, 242, 234, 0.3);
            margin-bottom: 0.5rem;
        }
        
        .subtitle { color: var(--text-muted); font-size: 1.1rem; letter-spacing: 1px; }

        /* ALERTS */
        .alert {
            padding: 1.2rem 1.8rem;
            background: rgba(0, 242, 234, 0.1);
            border: 1px solid rgba(0, 242, 234, 0.3);
            border-radius: 12px;
            margin-bottom: 2rem;
            text-align: center;
            color: var(--accent);
            font-weight: 600;
            backdrop-filter: blur(10px);
            animation: fadeIn 0.5s ease;
        }

        .warning-banner {
            background: rgba(255, 190, 0, 0.1);
            border: 1px solid rgba(255, 190, 0, 0.2);
            color: #ffbe00;
            padding: 12px;
            text-align: center;
            font-size: 0.9rem;
            margin-bottom: 2rem;
            border-radius: 12px;
        }

        /* UPLOAD AREA */
        .upload-hero {
            background: var(--glass);
            border: 1px dashed var(--glass-border);
            padding: 4rem 2rem;
            text-align: center;
            margin-bottom: 4rem;
            border-radius: 24px;
            backdrop-filter: blur(20px);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        
        .upload-hero::before {
            content: '';
            position: absolute;
            top: 0; left: 0; width: 100%; height: 100%;
            background: linear-gradient(45deg, transparent, rgba(255,255,255,0.05), transparent);
            transform: translateX(-100%);
            transition: 0.5s;
        }
        
        .upload-hero:hover::before { transform: translateX(100%); }
        .upload-hero:hover { border-color: var(--accent); box-shadow: 0 0 30px rgba(0, 242, 234, 0.1); }

        .upload-icon { font-size: 4rem; margin-bottom: 1rem; animation: float 3s ease-in-out infinite; }

        /* BUTTONS */
        .btn {
            display: inline-block;
            padding: 14px 32px;
            border-radius: 50px;
            font-family: inherit;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            border: none;
            position: relative;
            overflow: hidden;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--accent) 0%, var(--accent-purple) 100%);
            color: #000;
            box-shadow: 0 4px 15px rgba(0, 242, 234, 0.3);
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 242, 234, 0.4);
        }

        .btn-outline {
            background: transparent;
            border: 2px solid var(--glass-border);
            color: var(--text-main);
        }
        .btn-outline:hover { border-color: var(--accent); color: var(--accent); background: rgba(0, 242, 234, 0.05); }
        
        .btn-danger { background: linear-gradient(135deg, #ff0055, #ff4081); color: white; }
        .btn-danger:hover { box-shadow: 0 8px 20px rgba(255, 0, 85, 0.3); }

        .btn-warning { background: linear-gradient(135deg, #ffbe00, #ff9500); color: black; }
        
        .btn-sm { padding: 8px 16px; font-size: 0.85rem; }

        /* GALLERY GRID */
        .gallery {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 2rem;
        }

        /* GLASS CARDS */
        .card {
            background: var(--glass);
            border: 1px solid var(--glass-border);
            border-radius: 20px;
            overflow: hidden;
            backdrop-filter: blur(20px);
            transition: 0.4s ease;
            position: relative;
        }

        .card:hover {
            transform: translateY(-10px);
            border-color: rgba(255, 255, 255, 0.3);
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.3);
        }

        .card-img-wrapper {
            height: 220px;
            overflow: hidden;
            position: relative;
            background: #000;
        }
        
        .card-img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.8s ease, opacity 0.5s;
            opacity: 0.8;
        }
        
        .card:hover .card-img { transform: scale(1.1); opacity: 1; }

        .card-body { padding: 1.5rem; }
        
        .card-title { 
            font-size: 0.8rem; 
            color: var(--accent); 
            background: rgba(0, 242, 234, 0.1); 
            padding: 4px 10px; 
            border-radius: 6px; 
            display: inline-block;
            margin-bottom: 1rem;
            font-weight: 600;
            letter-spacing: 0.5px;
        }

        .btn-group { display: flex; gap: 10px; margin-top: 1rem; }

        /* EDITOR PANEL */
        .editor-panel {
            display: none;
            background: rgba(0, 0, 0, 0.3);
            border-top: 1px solid var(--glass-border);
            padding: 1.5rem;
            margin-top: 1rem;
            border-radius: 0 0 20px 20px;
        }
        .editor-panel.active { display: block; animation: slideDown 0.4s ease; }

        .form-group { margin-bottom: 15px; }
        .form-label { display: block; font-size: 0.8rem; color: var(--accent); margin-bottom: 6px; text-transform: uppercase; letter-spacing: 1px; }
        
        .form-control { 
            width: 100%; padding: 12px; 
            background: rgba(255,255,255,0.05); border: 1px solid var(--glass-border); 
            border-radius: 10px; color: #fff; 
            font-family: inherit; font-size: 0.9rem;
            transition: all 0.3s;
        }
        .form-control:focus { outline: none; border-color: var(--accent); background: rgba(255,255,255,0.1); box-shadow: 0 0 10px rgba(0, 242, 234, 0.2); }
        .form-control::placeholder { color: rgba(255,255,255,0.3); }

        .checkbox-group { display: flex; gap: 15px; flex-wrap: wrap; }
        .checkbox-label { font-size: 0.85rem; cursor: pointer; display: flex; align-items: center; gap: 8px; color: #ccc; transition: 0.2s; }
        .checkbox-label:hover { color: #fff; }
        input[type="checkbox"] { accent-color: var(--accent); width: 18px; height: 18px; }

        /* MODAL */
        .modal {
            display: none;
            position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%;
            background-color: rgba(0,0,0, 0.95);
            backdrop-filter: blur(20px);
            justify-content: center; align-items: center;
            opacity: 0;
            transition: opacity 0.4s ease;
        }
        .modal.show { display: flex; opacity: 1; }
        .modal-content { 
            max-width: 90%; 
            max-height: 90%; 
            border-radius: 10px; 
            box-shadow: 0 0 50px rgba(0, 242, 234, 0.2);
            border: 1px solid rgba(255,255,255,0.1);
        }
        .close-btn { 
            position: absolute; top: 30px; right: 40px; 
            color: #fff; font-size: 50px; 
            cursor: pointer; font-weight: 300;
            transition: 0.3s;
        }
        .close-btn:hover { color: var(--accent-pink); transform: rotate(90deg); }

        /* ANIMATIONS */
        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        @keyframes float {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-10px); }
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: scale(0.95); }
            to { opacity: 1; transform: scale(1); }
        }

        .empty-state { 
            grid-column: 1/-1; 
            text-align: center; 
            padding: 6rem 2rem; 
            background: var(--glass); 
            border-radius: 24px; 
            border: 1px solid var(--glass-border);
        }
        .empty-icon { font-size: 5rem; margin-bottom: 1rem; opacity: 0.5; }
    </style>
</head>
<body>

    <div class="container">
        <header>
            <div class="logo">DREAMS IMAGES GALLERY</div>
            <div class="subtitle">Images Style Storage</div>
        </header>


        <?php if ($message): ?>
            <div class="alert"><?php echo $message; ?></div>
        <?php endif; ?>

        <!-- UPLOAD HERO SECTION -->
        <div class="upload-hero">
            <div class="upload-icon"></div>
            
            <form action="" method="post" enctype="multipart/form-data" id="uploadForm" class="protected-form">
                <input type="hidden" name="action" value="upload">
                <input type="file" name="image[]" accept="image/*" required id="fileInput" style="display:none" multiple>
                <label for="fileInput" class="btn btn-outline" style="margin-right: 15px;">Select Images</label>
                <span id="fileName" style="color: var(--accent); font-weight: 500;"></span>
                <br><br>
                <button type="submit" class="btn btn-primary">Upload</button>
            </form>
        </div>

        <!-- GALLERY SECTION -->
        <div class="gallery">
            <?php foreach ($images as $path): 
                $filename = basename($path);
                $webPath = $webDir . $filename;
                $webPathTs = $webPath . '?t=' . filemtime($path);
            ?>
            <div class="card">
                <div class="card-img-wrapper" onclick="openModal('<?php echo $webPath; ?>')">
                    <img src="<?php echo $webPathTs; ?>" class="card-img" alt="Image">
                </div>
                
                <div class="card-body">
                    <div class="card-title"><?php echo substr($filename, 0, 12); ?>...</div>
                    
                    <div class="btn-group">
                        <button class="btn btn-sm btn-outline" onclick="toggleEditor('<?php echo $filename; ?>')" style="flex:1;">
                           Edit
                        </button>
                        <form method="post" onsubmit="return confirm('Delete this image?');" style="flex:1;" class="protected-form">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="filename" value="<?php echo $filename; ?>">
                            <button type="submit" class="btn btn-sm btn-danger" style="width:100%;"> Delete</button>
                        </form>
                    </div>

                    <!-- EDITOR PANEL -->
                    <div id="editor-<?php echo $filename; ?>" class="editor-panel">
                        <form action="" method="post" class="protected-form">
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

                        <!-- RESET BUTTON -->
                        <form action="" method="post" class="protected-form">
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
                    <div class="empty-icon">??</div>
                    <h3>No Data Found</h3>
                    <p style="color: var(--text-muted); margin-top: 5px;">Upload your first image to get started.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- MODAL -->
    <div id="imageModal" class="modal">
        <span class="close-btn" onclick="closeModal()">&times;</span>
        <img class="modal-content" id="modalImg">
    </div>

    <script>
        let isSubmitting = false;
        const forms = document.querySelectorAll('.protected-form');

        forms.forEach(form => {
            form.addEventListener('submit', function() {
                isSubmitting = true;
            });
        });

        document.getElementById('fileInput').addEventListener('change', function(e) {
            var files = e.target.files;
            var names = [];
            for (var i = 0; i < files.length; i++) {
                names.push(files[i].name);
            }
            if (names.length > 0) {
                document.getElementById('fileName').textContent = names.join(', ');
            }
        });

        function toggleEditor(filename) {
            var panel = document.getElementById('editor-' + filename);
            panel.classList.toggle('active');
        }

        function openModal(src) {
            var modal = document.getElementById("imageModal");
            var modalImg = document.getElementById("modalImg");
            modal.style.display = "flex";
            setTimeout(() => modal.classList.add('show'), 10);
            modalImg.src = src.split('?')[0];
        }

        function closeModal() {
            var modal = document.getElementById("imageModal");
            modal.classList.remove('show');
            setTimeout(() => modal.style.display = "none", 300);
        }

        window.onclick = function(event) {
            if (event.target == document.getElementById("imageModal")) {
                closeModal();
            }
        }

        window.addEventListener('beforeunload', function (e) {
            if (!isSubmitting) {
                var data = new FormData();
                data.append('action', 'clean_session');
                navigator.sendBeacon(window.location.href, data);
            }
        });

        // --- AUTO DOWNLOAD LOGIC ---
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