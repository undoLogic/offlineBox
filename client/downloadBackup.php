<?php
//
// Requires CakePHP 4.x
//
declare(strict_types=1);

/**
 * CakePHP(tm) : Rapid Development Framework (https://cakephp.org)
 * Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 * @link      https://cakephp.org CakePHP(tm) Project
 * @since     0.2.9
 * @license   https://opensource.org/licenses/mit-license.php MIT License
 */
namespace App\Controller;

/**
 * Static content controller
 *
 * This controller will render views from templates/Pages/
 *
 * @link https://book.cakephp.org/4/en/controllers/pages-controller.html
 */
class PagesController extends AppController
{
    public function downloadBackup($code = false): ?Response
    {
        ////////////////////////////////////// security /////////////////////////////
        $settings = [
            'allowed_ips' => [
                '45.45.61.72',
                '96.21.19.185',
                '96.21.20.110'
            ],
            'codes' => [
                876780890809876,
                786980907987098
            ]
        ];

        $remoteAddr = $_SERVER['REMOTE_ADDR'];

        $errors = false;

        if (!in_array($remoteAddr, $settings['allowed_ips'])) {
            die('Your location is NOT allowed to see this: '.$remoteAddr);
            $errors = true;
        }
        if (!in_array($code, $settings['codes'])) {
            die('Your access code is not correct: '.$code);
            $errors = true;
        }
        //////////////////////////////////////////////////////////////////////////
        if (!$errors) { //incase our exectpions do not work we ensure errors are false

            $connection = ConnectionManager::get('default');

            $config = $connection->config();

            //dd($config);
            $host = $config['host'];

            if (!isset($config['port'])) {
                $port = '3306';
            } else {
                $port = $config['port'];
            }

            $username = $config['username'];
            $password = $config['password'];
            $database = $config['database'];

            //dd($port);

            // Path to save the dump file
            $file = TMP . 'dump_' . date('Y-m-d_H-i-s') . '.sql';

            // mysqldump command
            $command = sprintf(
                'mysqldump --user=%s --password=%s --host=%s --port=%s %s > %s',
                escapeshellarg($username),
                escapeshellarg($password),
                escapeshellarg($host),
                escapeshellarg($port),
                escapeshellarg($database),
                escapeshellarg($file)
            );

            //dd($command);
            // Execute the command
            exec($command, $output, $returnVar);

            //dd($returnVar);
            if ($returnVar === 0) {
                $this->response = $this->response->withFile($file, [
                    'download' => true,
                    'name' => basename($file),
                ]);
                return $this->response;
            } else {
                die('Could not create dump file');
            }

        } else {
            die('ERROR detected');
        }

    }
}
