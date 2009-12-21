<?php
/*
 * This file is part of EC-CUBE
 *
 * Copyright(c) 2000-2007 LOCKON CO.,LTD. All Rights Reserved.
 *
 * http://www.lockon.co.jp/
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA  02111-1307, USA.
 *
 * 空メール受け付けアドレスのコマンド名とトークンの間の区切り文字
 */
define('MOBILE_KARA_MAIL_EXTENSION_DELIMITER', '_');

/**
 * モバイルサイトの空メールを扱うクラス
 */
class SC_MobileKaraMail {
    /**
     * 環境変数から MTA を判別し、対応する SC_MobileKaraMail またはそのサブクラス
     * のインスタンスを作成する。
     *
     * @return object SC_MobileKaraMail またはそのサブクラスのインスタンスを返す。
     */
    function &factory() {
        if (isset($_ENV['EXTENSION'])) {
            $objInstance = new SC_MobileKaraMail_Postfix;
        } elseif (isset($_ENV['DEFAULT'])) {
            $objInstance = new SC_MobileKaraMail_Qmail;
        } else {
            $objInstance = new SC_MobileKaraMail;
        }

        return $objInstance;
    }

    /**
     * 標準入力からメールを読み込み、必要な情報を取得する。
     *
     * @return void
     */
    function parse() {
        if (@$this->parsed) {
            return;
        }

        require_once DATA_PATH . '/module/Mail/mimeDecode.php';

        $fp = fopen('php://stdin', 'r');

        // From 行を解析する。
        $from_line = rtrim(fgets($fp));
        if (preg_match('/^From\\s+"?([^\\s"@]+)"?@([^\\s@]+)/', $from_line, $matches)) {
            $this->sender = $matches[1] . '@' . $matches[2];
        } else {
            trigger_error("Invalid from line: $from_line");
            $this->sender = null;
        }

        // 残りのヘッダーを解析する。
        $data = '';
        while (!feof($fp)) {
            $data .= fgets($fp);
            if (rtrim($data, "\r\n") == '') {
                break;
            }
        }
        $structure = Mail_mimeDecode::decode(array('input' => $data));
        $this->recipient = @$structure->headers['to'];

        // 宛先アドレスから拡張部分を取得する。
        $pos = strpos($this->recipient, MOBILE_KARA_MAIL_ADDRESS_DELIMITER);
        if ($pos !== false) {
            $extension_and_domain = substr($this->recipient, $pos + 1);
            $pos = strpos($extension_and_domain, '@');
            if ($pos !== false) {
                $this->extension = substr($extension_and_domain, 0, $pos);
            } else {
                $this->extension = $extension_and_domain;
            }
        } else {
            trigger_error("Invalid recipient: {$this->recipient}");
            $this->extension = null;
        }

        $this->parsed = true;
    }

    /**
     * 配信が完了したことを示す終了ステータスでプログラムを終了する。
     *
     * @return void
     */
    function success() {
        exit(0);
    }

    /**
     * 一時的なエラーを示す終了ステータスでプログラムを終了する。
     *
     * @return void
     */
    function temporaryFailure() {
        exit(75);
    }

    /**
     * 送信者のメールアドレスを取得する。
     *
     * parse() 実行後に使用すること。
     *
     * @return string|false 送信者のメールアドレスを返す。取得できなかった場合は false を返す。
     */
    function getSender() {
        return isset($this->sender) ? $this->sender : false;
    }

    /**
     * 宛先のメールアドレスの拡張部分からコマンド名を取得する。
     *
     * parse() 実行後に使用すること。
     *
     * @return string|false コマンド名を返す。取得できなかった場合は false を返す。
     */
    function getCommand() {
        if (!isset($this->extension)) {
            return false;
        }

        $pos = strpos($this->extension, MOBILE_KARA_MAIL_EXTENSION_DELIMITER);
        if ($pos === false) {
            return false;
        }

        return substr($this->extension, 0, $pos);
    }

    /**
     * 宛先のメールアドレスの拡張部分からトークンを取得する。
     *
     * parse() 実行後に使用すること。
     *
     * @return string|false トークンを返す。取得できなかった場合は false を返す。
     */
    function getToken() {
        if (!isset($this->extension)) {
            return false;
        }

        $pos = strpos($this->extension, MOBILE_KARA_MAIL_EXTENSION_DELIMITER);
        if ($pos === false) {
            return false;
        }

        return substr($this->extension, $pos + 1);
    }
}

/**
 * モバイルサイトの空メールを扱うクラス (Postfix用)
 */
class SC_MobileKaraMail_Postfix extends SC_MobileKaraMail {
    /**
     * @see SC_MobileKaraMail::parse()
     */
    function parse() {
        if (@$this->parsed) {
            return;
        }

        $this->sender = $_ENV['SENDER'];
        $this->recipient = $_ENV['RECIPIENT'];
        $this->extension = $_ENV['EXTENSION'];

        $this->parsed = true;
    }
}

/**
 * モバイルサイトの空メールを扱うクラス (qmail用)
 */
class SC_MobileKaraMail_Qmail extends SC_MobileKaraMail {
    /**
     * @see SC_MobileKaraMail::parse()
     */
    function parse() {
        if (@$this->parsed) {
            return;
        }

        $this->sender = $_ENV['SENDER'];
        $this->recipient = $_ENV['RECIPIENT'];
        $this->extension = $_ENV['DEFAULT'];

        $this->parsed = true;
    }

    /**
     * 一時的なエラーを示す終了ステータスでプログラムを終了する。
     *
     * @return void
     */
    function temporaryFailure() {
        exit(111);
    }
}
?>
