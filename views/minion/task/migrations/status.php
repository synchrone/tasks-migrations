<?php foreach($groups as $group => $status): ?>
 >> <?php echo $group?>

    Current version: <?php echo $status['timestamp'] !== null ?
        sprintf('%s (%d)',Date::formatted_time($status['timestamp']), $status['timestamp']) :
        'No applied migrations'
    ?> <?php echo $status['description']?>

    Available migrations: <?php echo $status['count_available']?>

<?php endforeach; ?>
