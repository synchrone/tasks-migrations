<?php foreach($groups as $group => $status): ?>
 >> <?php echo $group?>

    Current version: <?php printf('%s (%d)',Date::formatted_time($status['timestamp']), $status['timestamp'])?> <?php echo $status['description']?>

    Available migrations: <?php echo $status['count_available']?>

<?php endforeach; ?>
