<?php

if (!empty($_FILES['deploy'])) {
    require_once("Tar.php");

    $tempname = $_FILES['deploy']['tmp_name'];
    $filename = $_FILES['deploy']['name'];

    $upload_target = __DIR__ . "/temp/" . $filename;
    move_uploaded_file($tempname, $upload_target);

    if (file_exists($upload_target)) {
        echo "Archive uploaded\n";

        $archive = new Archive_Tar($upload_target);
        $deploy_target = $deploy_workspace . "/deploy_target";
        if (!file_exists($deploy_target)) {
            mkdir($deploy_target, 0777);
        }
        $archive->extract($deploy_target);
        echo "Archive extracted\n";

        unlink($upload_target);
        echo "Archive deleted\n";

        copy($deploy_workspace . "/config.php", $deploy_target . "/config.php");
        echo "Extra files copied\n";

        if (file_exists($deploy_current)) {
            recurse_copy($deploy_current, $deploy_old);
        }
        echo "Old build backed up\n";

        @rename($deploy_target, $deploy_current);
        echo "New build deployed\n";

        echo "Done!\n";
    }
} elseif (isset($_POST['rollback'])) {
    copy($deploy_old, $deploy_current);
} else { ?>
<form action="index.php" method="post" enctype="multipart/form-data">
    <input type="file" name="deploy">
    <input type="submit">
</form>
<?php }
