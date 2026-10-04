<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_layout.php';

admin_require();

$fields = ['Title', 'Author', 'Category', 'Publisher', 'Edition', 'Language', 'page', 'weight',
    'MRP', 'Price', 'Discount', 'Available', 'Description',
    'ArchiveId', 'Year', 'Rights', 'Subject', 'SourceName', 'SourceUpload',
    'ReadUrl', 'DownloadUrl', 'TextUrl', 'EpubUrl', 'DetailUrl', 'PdfFile', 'PdfSize'];

$nullable = ['page', 'weight', 'Discount', 'Year', 'PdfSize', 'EpubUrl', 'TextUrl', 'Rights', 'Subject'];

$pid    = trim((string) ($_GET['pid'] ?? $_POST['pid'] ?? ''));
$isNew  = $pid === '';
$errors = [];

$form = array_fill_keys($fields, '');
$form['Discount'] = '0';
$form['Available'] = '0';
$form['MRP'] = '0';
$form['Price'] = '0';
$form['Language'] = 'Punjabi';
$form['SourceName'] = 'Internet Archive';

if (!$isNew) {
    $existing = one('SELECT * FROM products WHERE PID = ?', [$pid]);
    if (!$existing) {
        flash('No book with id ' . $pid . '.', 'err');
        admin_redirect('books.php');
    }
    foreach ($fields as $k) {
        $form[$k] = (string) ($existing[$k] ?? '');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    // Handle audiobook generation
    if (isset($_POST['generate_audiobook'])) {
        csrf_check();
        if (!$isNew && ($form['TextUrl'] !== '' || $form['EpubUrl'] !== '')) {
            // Placeholder: replace with your actual VoiceStudio/piper command
            $pidEsc = escapeshellarg($pid);
            $outEsc = escapeshellarg("/home/jatt/BookStore-v2/public/audiobooks/$pid.mp3");
            $src = $form['TextUrl'] !== '' ? $form['TextUrl'] : $form['EpubUrl'];
            $srcEsc = escapeshellarg($src);
            $voiceModel = '/home/jatt/VoiceStudio/your_voice_name';  // UPDATE THIS PATH
            
            // Example using piper (install: pipx install piper-tts):
            $cmd = "cd /home/jatt && echo 'SOURCE=$srcEsc' > /tmp/audiojob-$pidEsc.sh && echo 'OUTPUT=$outEsc' >> /tmp/audiojob-$pidEsc.sh && echo 'MODEL=$voiceModel' >> /tmp/audiojob-$pidEsc.sh && chmod +x /tmp/audiojob-$pidEsc.sh && nohup /tmp/audiojob-$pidEsc.sh 2>&1 | logger -t audiogen-$pidEsc &";
            exec($cmd);
            flash('Audiobook job queued for ' . $pid . '. Edit /tmp/audiojob-' . $pid . '.sh with your actual TTS command. Watch: journalctl -f -t audiogen-' . $pid);
        } else {
            flash('No Text/EPUB source for this book.', 'err');
        }
        admin_redirect('book-edit.php?pid=' . urlencode($pid));
    }

    $postedPid = trim((string) ($_POST['pid'] ?? ''));
    $isNew = $postedPid === '';

    $rawCategory = trim((string) ($_POST['Category'] ?? ''));

    foreach ($fields as $k) {
        $form[$k] = trim((string) ($_POST[$k] ?? ''));
    }
    $form['Title']        = flat($form['Title']);
    $form['Author']       = flat($form['Author']);
    $form['Category']     = canon_category($form['Category']);
    $form['Publisher']    = flat($form['Publisher']);
    $form['Edition']      = flat($form['Edition']);
    $form['Language']     = flat($form['Language']);
    $form['Description']  = flat($form['Description']);

    if ($isNew) {
        $pid = trim((string) ($_POST['new_pid'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9_.-]{1,150}$/', $pid)) {
            $errors['PID'] = 'Id must be 1-150 letters, digits, dots, dashes or underscores.';
        } elseif (one('SELECT PID FROM products WHERE PID = ?', [$pid])) {
            $errors['PID'] = 'Book id ' . $pid . ' already exists.';
        }
    }

    if ($form['Title'] === '') {
        $errors['Title'] = 'Title is required.';
    }
    if ($form['Author'] === '') {
        $errors['Author'] = 'Author is required.';
    }
    if ($rawCategory === '') {
        $errors['Category'] = 'Category is required.';
    }
    if (!is_numeric($form['MRP']) || (float) $form['MRP'] < 0) {
        $errors['MRP'] = 'MRP must be 0 or more.';
    }
    if (!is_numeric($form['Price']) || (float) $form['Price'] < 0) {
        $errors['Price'] = 'Price must be 0 or more.';
    }
    if (!is_numeric($form['Discount']) || (float) $form['Discount'] < 0 || (float) $form['Discount'] > 100) {
        $errors['Discount'] = 'Discount must be between 0 and 100.';
    }
    if (!ctype_digit($form['Available'])) {
        $errors['Available'] = 'Stock must be a whole number.';
    }
    foreach (['page', 'weight'] as $k) {
        if ($form[$k] !== '' && !ctype_digit($form[$k])) {
            $errors[$k] = $k === 'page' ? 'Pages must be a whole number.' : 'Weight must be a whole number.';
        }
    }
    if ($form['Year'] !== '' && !ctype_digit($form['Year'])) {
        $errors['Year'] = 'Year must be a whole number.';
    }
    if ($form['PdfSize'] !== '' && !ctype_digit($form['PdfSize'])) {
        $errors['PdfSize'] = 'PDF size must be a whole number of bytes.';
    }
    foreach (['ReadUrl', 'DownloadUrl', 'TextUrl', 'EpubUrl', 'DetailUrl'] as $k) {
        if ($form[$k] !== '' && !filter_var($form[$k], FILTER_VALIDATE_URL)) {
            $errors[$k] = 'Must be a valid http(s) URL.';
        }
    }

    if (!$errors) {
        $vals = [];
        foreach ($fields as $k) {
            if ($form[$k] === '' && in_array($k, $nullable, true)) {
                $vals[] = null;
            } else {
                $vals[] = $form[$k];
            }
        }

        if ($isNew) {
            $set = implode(', ', array_map(fn($c) => "$c = ?", $fields));
            q("INSERT INTO products (PID, " . implode(', ', $fields) . ", UpdatedAt) VALUES (?, $set, NOW())",
                array_merge([$pid], $vals));
            flash('Created book ' . $pid . '. Rebuild to publish it.');
        } else {
            $set = implode(', ', array_map(fn($c) => "$c = ?", $fields));
            q("UPDATE products SET $set, UpdatedAt = NOW() WHERE PID = ?", array_merge($vals, [$pid]));
            flash('Saved changes to book ' . $pid . '. Rebuild to publish them.');
        }
        admin_redirect('book-edit.php?pid=' . urlencode($pid));
    }
}

admin_header($isNew ? 'Add book' : 'Edit book ' . $pid, 'books');
flash_show();

// Reminder / TODO notice
if (!$isNew) {
    $hasText = $form['TextUrl'] !== '';
    $hasEpub = $form['EpubUrl'] !== '';
    $audioPath = '/home/jatt/BookStore-v2/public/audiobooks/' . $pid . '.mp3';
    $audioExists = is_file($audioPath);
    $audioUrl = PUBLIC_URL . '/audiobooks/' . $pid . '.mp3';
    ?>
    <div class="panel todo" style="margin-bottom:1rem; padding:0.8rem 1rem; background:#fff8e1; border:1px solid #ffd54f; border-radius:6px;">
      <strong>🔔 TODO:</strong> Audiobook generation for <code><?= e($pid) ?></code>
      <?php if ($audioExists): ?>
        <span style="color:#2e7d32;"> ✓ Already generated → <a href="<?= e($audioUrl) ?>" target="_blank">listen</a></span>
      <?php else: ?>
        <span style="color:#e65100;"> — not generated yet</span>
      <?php endif; ?>
      <br><small>Sources: <?= $hasText ? '✓ Text' : '✗ Text' ?> | <?= $hasEpub ? '✓ EPUB' : '✗ EPUB' ?>
      | Voice model: <code>/home/jatt/VoiceStudio/my_voice_model</code> (create it first with VoiceStudio)</small>
    </div>
    <?php
}
?>
<h1><?= $isNew ? 'Add book' : 'Edit book' ?> <?php if (!$isNew): ?><code class="muted"><?= e($pid) ?></code><?php endif; ?></h1>

<?php if ($errors): ?>
  <div class="flash err"><b>Could not save:</b>
    <ul><?php foreach ($errors as $m): ?><li><?= e($m) ?></li><?php endforeach; ?></ul>
  </div>
<?php endif; ?>

<form method="post" action="book-edit.php" class="editor">
  <?= csrf_field() ?>
  <input type="hidden" name="pid" value="<?= e($isNew ? '' : $pid) ?>">

  <?php if ($isNew): ?>
    <label>Book ID (e.g. ENT-126)
      <input name="new_pid" value="<?= e($_POST['new_pid'] ?? '') ?>" required pattern="[A-Za-z0-9_-]{1,25}">
      <small class="hint">Letters, digits, dashes and underscores. Cannot be changed later.</small>
    </label>
  <?php endif; ?>

  <div class="grid2">
    <label>Title
      <input name="Title" value="<?= e($form['Title']) ?>" required>
    </label>
    <label>Author
      <input name="Author" value="<?= e($form['Author']) ?>" required list="authors">
      <datalist id="authors">
        <?php foreach (array_column(rows('SELECT DISTINCT Author FROM products ORDER BY Author'), 'Author') as $a): ?>
          <option value="<?= e($a) ?>"></option>
        <?php endforeach; ?>
      </datalist>
    </label>
    <label>Category
      <input name="Category" value="<?= e($form['Category']) ?>" required list="cats">
      <datalist id="cats">
        <?php foreach (array_column(rows('SELECT DISTINCT Category FROM products ORDER BY Category'), 'Category') as $c): ?>
          <option value="<?= e($c) ?>"></option>
        <?php endforeach; ?>
      </datalist>
    </label>
    <label>Publisher
      <input name="Publisher" value="<?= e($form['Publisher']) ?>">
    </label>
    <label>Edition
      <input name="Edition" value="<?= e($form['Edition']) ?>">
    </label>
    <label>Language
      <input name="Language" value="<?= e($form['Language']) ?>">
    </label>
  </div>

  <div class="grid4">
    <label>Year
      <input name="Year" inputmode="numeric" value="<?= e($form['Year']) ?>">
    </label>
    <label>PDF size (bytes)
      <input name="PdfSize" inputmode="numeric" value="<?= e($form['PdfSize']) ?>">
    </label>
    <label>Rights / licence
      <input name="Rights" value="<?= e($form['Rights']) ?>">
      <small class="hint">Leave blank if unknown &mdash; shown as &ldquo;not stated&rdquo;.</small>
    </label>
    <label>Source
      <input name="SourceName" value="<?= e($form['SourceName']) ?>">
    </label>
  </div>

  <fieldset class="sub">
    <legend>Reading &amp; download links</legend>
    <p class="hint">All files are hosted by the source. The reader embeds the source&rsquo;s online viewer.</p>
    <label>Archive item id
      <input name="ArchiveId" value="<?= e($form['ArchiveId']) ?>">
    </label>
    <label>Read online URL
      <input name="ReadUrl" value="<?= e($form['ReadUrl']) ?>" placeholder="https://archive.org/details/ITEM?view=theater&amp;ui=embed&amp;wrapper=false">
    </label>
    <label>PDF download URL
      <input name="DownloadUrl" value="<?= e($form['DownloadUrl']) ?>">
    </label>
    <label>Plain text URL
      <input name="TextUrl" value="<?= e($form['TextUrl']) ?>">
    </label>
    <label>EPUB URL
      <input name="EpubUrl" value="<?= e($form['EpubUrl']) ?>">
    </label>
    <label>Source page URL
      <input name="DetailUrl" value="<?= e($form['DetailUrl']) ?>">
    </label>
    <label>PDF file name
      <input name="PdfFile" value="<?= e($form['PdfFile']) ?>">
    </label>
    <label>Uploaded by
      <input name="SourceUpload" value="<?= e($form['SourceUpload']) ?>">
    </label>
  </fieldset>

  <label>Subjects
    <textarea name="Subject" rows="2"><?= e($form['Subject']) ?></textarea>
  </label>

  <details class="legacy">
    <summary>Legacy commerce fields (unused &mdash; everything is free)</summary>
    <div class="grid4">
      <label>MRP (&#8377;)
        <input name="MRP" inputmode="decimal" value="<?= e($form['MRP']) ?>">
      </label>
      <label>Price (&#8377;)
        <input name="Price" inputmode="decimal" value="<?= e($form['Price']) ?>">
      </label>
      <label>Discount (%)
        <input name="Discount" inputmode="numeric" value="<?= e($form['Discount']) ?>">
      </label>
      <label>Stock
        <input name="Available" inputmode="numeric" value="<?= e($form['Available']) ?>">
      </label>
    </div>
    <div class="grid2">
      <label>Pages
        <input name="page" inputmode="numeric" value="<?= e($form['page']) ?>">
      </label>
      <label>Weight (g)
        <input name="weight" inputmode="numeric" value="<?= e($form['weight']) ?>">
      </label>
    </div>
  </details>

  <label>Description
    <textarea name="Description" rows="7"><?= e($form['Description']) ?></textarea>
    <small class="hint">First lines are used for the page summary and search results.</small>
  </label>

  <div class="actions">
    <button class="btn" type="submit"><?= $isNew ? 'Create book' : 'Save changes' ?></button>
    <a class="btn ghost" href="books.php">Cancel</a>
    <?php if (!$isNew): ?>
      <a class="btn ghost" href="<?= e(PUBLIC_URL) ?>/books/<?= e(book_slug($pid)) ?>.html" target="_blank" rel="noopener">View on site</a>
      <form method="post" action="book-edit.php" style="display:inline">
        <?= csrf_field() ?>
        <input type="hidden" name="pid" value="<?= e($pid) ?>">
        <button class="btn" type="submit" name="generate_audiobook" 
                <?= ($form['TextUrl'] === '' && $form['EpubUrl'] === '') ? 'disabled' : '' ?>
                title="<?= ($form['TextUrl'] === '' && $form['EpubUrl'] === '') ? 'No Text/EPUB source' : 'Generates MP3 via VoiceStudio (requires voice model)' ?>">
          Generate audiobook
        </button>
      </form>
    <?php endif; ?>
  </div>
</form>
<?php admin_footer(); ?>