# LinkedEvents API Client

Extremely basic HTTP Call wrapper for LinkedEvents style API's.

Requires PHP 7.4, or later.

## Installing

```bash
composer require devgeniem/linkedevents-client
```

## Usage

```php
<?php

require_once 'vendor/autoload.php';

$client = new \Geniem\LinkedEvents\LinkedEventsClient( 'https://www.example.com/api/v2' );
$multiple = $client->get_all( 'event', [
    'start' => '2021-01-17',
    'end'   => '2021-01-20',
] );

$single = $client->get( 'event/system:kKCxAqpRX' );
```

## License

This project is licensed under the [GPL-3.0 License](LICENSE).

## Contributing

Contributions are highly welcome! Just leave a pull request of your awesome well-written must-have feature.
