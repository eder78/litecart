<?php
  breadcrumbs::add(language::translate('title_scan_translations', 'Scan Translations'));

  if (!empty($_POST['scan'])) {

    ob_start();

    $dir_iterator = new RecursiveDirectoryIterator(FS_DIR_APP);
    $iterator = new RecursiveIteratorIterator($dir_iterator, RecursiveIteratorIterator::SELF_FIRST);

    $files = 0;
    $found = 0;
    $new_translations = 0;
    $updated = 0;
    $translation_keys = [];
    $orphan = [];

    foreach ($iterator as $file) {
      if (pathinfo($file, PATHINFO_EXTENSION) != 'php') continue;
      //if (strpos(pathinfo($file, PATHINFO_FILENAME), 'vq2') !== false) continue;

      $files++;
      $contents = file_get_contents($file);

      $regexp = [
        'language::translate\((?:(?!\$)',
        '(?:(__CLASS__)?\.)?',
        '(?:[\'"])([^\'"]+)(?:[\'"])',
        '(?:,?\s+(?:[\'"])([^\'"]+)?(?:[\'"]))?',
        '(?:,?\s+?(?:[\'"])([^\'"]+)?(?:[\'"]))?',
        ')\)',
      ];

      $regexp = '#'. implode('', $regexp) .'#s';

      preg_match_all($regexp, $contents, $matches);

      $translations = [];

      if (!empty($matches)) {
        for ($i=0; $i<count($matches[1]); $i++) {
          if ($matches[1][$i]) {
            $key = substr(pathinfo($file, PATHINFO_BASENAME), 0, strpos(pathinfo($file, PATHINFO_BASENAME), '.')) . $matches[2][$i];
          } else {
            $key = $matches[2][$i];
          }
          $translations[$key] = str_replace(["\\r", "\\n"], ["\r", "\n"], $matches[3][$i]);
          $translation_keys[] = $key;
        }
      }

      foreach ($translations as $code => $translation) {

        $found++;

        $translations_query = database::query(
          "select text_en from ". DB_PREFIX ."translations
          where code = '". database::input($code) ."'
          limit 1;"
        );

        if (!$row = database::fetch($translations_query)) {

          $new_translations++;

          database::query(
            "insert into ". DB_PREFIX ."translations
            (code, text_en, html, date_created)
            values ('". database::input($code) ."', '". database::input($translation, true) ."', '". (($translation != strip_tags($translation)) ? 1 : 0) ."', '". date('Y-m-d H:i:s') ."');"
          );

          echo  $code . ' [ADDED]<br/>' . PHP_EOL;

        } else if (empty($row['text_en']) && !empty($translation) && !empty($_POST['update'])) {

          $updated++;

          database::query(
            "update ". DB_PREFIX ."translations
            set text_en = '". database::input($translation, true) ."'
            where code = '". database::input($code) ."'
            and text_en = ''
            limit 1;"
          );

          echo  $code . ' [UPDATED]<br/>' . PHP_EOL;
        }
      }
    }

    $settings_groups_query = database::query(
      "select `key` from ". DB_PREFIX ."settings_groups;"
    );

    while ($group = database::fetch($settings_groups_query)) {
      $translation_keys[] = 'settings_group:title_'.$group['key'];
      $translation_keys[] = 'settings_group:title_'.$group['key'];
    }

    $settings_query = database::query(
      "select `key` from ". DB_PREFIX ."settings
      where setting_group_key != '';"
    );

    while ($setting = database::fetch($settings_query)) {
      $translation_keys[] = 'settings_key:title_'.$setting['key'];
      $translation_keys[] = 'settings_key:description_'.$setting['key'];
    }

    $translations_query = database::query(
      "select * from ". DB_PREFIX ."translations;"
    );

    while ($translation = database::fetch($translations_query)) {
      if (!in_array($translation['code'], $translation_keys)) {
        $orphan[] = $translation;
      }
    }

    $log = ob_get_clean();

    cache::clear_cache('translations');

    notices::add('notices', sprintf(language::translate('text_found_d_translations', 'Found %d translations in %d files'), $found, $files));
    if ($new_translations) notices::add('notices', sprintf(language::translate('text_added_d_new_translations', 'Added %d new translations'), $new_translations));
    if ($updated) notices::add('notices', sprintf(language::translate('text_updated_d_translations', 'Updated %d translations'), $updated));

  }

  if (!empty($_POST['delete'])) {

    try {

      if (empty($_POST['translations'])) throw new Exception(language::translate('error_must_select_translations', 'You must select translations'));

      foreach ($_POST['translations'] as $code) {
        database::query(
          "delete from ". DB_PREFIX ."translations
          where code = '". database::input($code) ."'
          limit 1;"
        );
      }

      notices::add('success', sprintf(language::translate('text_deleted_d_translations', 'Deleted %d translations'), count($_POST['translations'])));

    } catch (Exception $e) {
      notices::add('errors', $e->getMessage());
    }
  }
?>
<style>
pre {
  white-space: pre-line;
}
table.data-table {
  white-space: wrap;
}
</style>

<div class="panel panel-app">
  <div class="panel-heading">
    <?php echo $app_icon; ?> <?php echo language::translate('title_scan_files_for_translations', 'Scan Files For Translations'); ?>
  </div>

  <div class="panel-body">
    <div class="row">
      <div class="col-md-4">
        <?php echo functions::form_draw_form_begin('scan_form', 'post'); ?>

          <p><?php echo language::translate('description_scan_for_translations', 'This will scan your files for translations. New translations will be added to the database.'); ?></p>

          <p><label><?php echo functions::form_draw_checkbox('update', '1'); ?> <?php echo language::translate('text_update_empty_translations', 'Update empty translations if applicable'); ?></label></p>

          <p><?php echo functions::form_draw_button('scan', language::translate('title_scan', 'Scan'), 'submit'); ?></p>

        <?php echo functions::form_draw_form_end(); ?>

        <?php if (!empty($_POST['scan'])) { ?>
        <pre id="log">
        <?php echo $log; ?>
        </pre>
        <?php } ?>
      </div>

      <?php if (!empty($_POST['scan']) && !empty($orphan)) { ?>
      <div class="col-md-8">

        <h2><?php echo language::translate('title_orphan_translations', 'Orphan Translations'); ?></h2>

          <?php echo functions::form_draw_form_begin('scan_form', 'post'); ?>

          <table class="table table-striped data-table">
            <thead>
              <tr>
                <th><?php echo functions::draw_fonticon('fa-check-square-o fa-fw checkbox-toggle', 'data-toggle="checkbox-toggle"'); ?></th>
                <th><?php echo language::translate('title_code', 'Code'); ?></th>
                <th><?php echo language::translate('title_translation', 'Translation'); ?></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($orphan as $row) { ?>
              <tr>
                <td><?php echo functions::form_draw_checkbox('translations[]', $row['code'], true); ?></td>
                <td><?php echo $row['code']; ?></td>
                <td><?php echo $row['text_'.language::$selected['code']]; ?></td>
              </tr>
              <?php } ?>
            </tbody>
          </table>

          <div class="btn-group">
            <?php echo functions::form_draw_button('delete', language::translate('title_delete', 'Delete'), 'submit', 'onclick="if (!window.confirm(\''. language::translate('text_are_you_sure', 'Are you sure?') .'\')) return false;"', 'delete'); ?>
          </div>

        <?php echo functions::form_draw_form_end(); ?>

      </div>
      <?php } ?>
    </div>
  </div>
</div>
