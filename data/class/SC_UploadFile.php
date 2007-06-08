<?php
/*
 * Copyright(c) 2000-2007 LOCKON CO.,LTD. All Rights Reserved.
 *
 * http://www.lockon.co.jp/
 */

$SC_UPLOADFILE_DIR = realpath(dirname( __FILE__));
require_once($SC_UPLOADFILE_DIR . "/../lib/gdthumb.php");
require_once($SC_UPLOADFILE_DIR . "/../include/ftp.php");

/* アップロードファイル管理クラス */
class SC_UploadFile {
    var $temp_dir;                  // 一時ファイル保存ディレクトリ
    var $save_dir;                  // ファイル保存ディレクト
    var $ftp_temp_dir;              // FTP先一時ディレクトリ(負荷分散時使用)
    var $ftp_save_dir;              // FTP先ファイル保存ディレクトリ(負荷分散時使用)
    var $multi_web_server_mode;     // 負荷分散フラグ(負荷分散時使用)
    var $keyname;                   // ファイルinputタグのname
    var $width;                     // 横サイズ
    var $height;                    // 縦サイズ
    var $arrExt;                    // 指定する拡張子
    var $temp_file;                 // 保存されたファイル名
    var $save_file;                 // DBから読み出したファイル名
    var $disp_name;                 // 項目名
    var $size;                      // 制限サイズ
    var $necessary;                 // 必須の場合:true
    var $image;                     // 画像の場合:true

    // ファイル管理クラス
    function SC_UploadFile($temp_dir, $save_dir, $ftp_temp_dir = "", $ftp_save_dir = "", $multi_web_server_mode = false) {
        $this->temp_dir = $temp_dir;
        $this->save_dir = $save_dir;
        $this->ftp_temp_dir = $ftp_temp_dir;
        $this->ftp_save_dir = $ftp_save_dir;
        $this->multi_web_server_mode = $multi_web_server_mode;
        $this->file_max = 0;
    }

    // ファイル情報追加
    function addFile($disp_name, $keyname, $arrExt, $size, $necessary=false, $width=0, $height=0, $image=true) {
        $this->disp_name[] = $disp_name;
        $this->keyname[] = $keyname;
        $this->width[] = $width;
        $this->height[] = $height;
        $this->arrExt[] = $arrExt;
        $this->size[] = $size;
        $this->necessary[] = $necessary;
        $this->image[] = $image;
    }
    // サムネイル画像の作成
    function makeThumb($src_file, $width, $height) {
        // 一意なIDを取得する。
        $uniqname = date("mdHi") . "_" . uniqid("");
        
        $objThumb = new gdthumb();
        
        // WEBサーバ負荷分散環境の場合
        if($this->multi_web_server_mode === true) {
            
            // FTP用ファイル一時格納用ディレクトリ作成
            $ftp_temp_dir = $this->makeFtpTempDir($this->temp_dir);
            $dst_file = $ftp_temp_dir . $uniqname;
            $ret = $objThumb->Main($src_file, $width, $height, $dst_file);
            $this->ftpMoveFile($this->ftp_temp_dir . basename($ret[1]), $ret[1]);
        } else {
            $dst_file = $this->temp_dir . $uniqname;
            $ret = $objThumb->Main($src_file, $width, $height, $dst_file);          
        }
        
        if($ret[0] != 1) {
            // エラーメッセージの表示
            print($ret[1]);
            exit;
        }
        
        return basename($ret[1]);
    }
        
    // アップロードされたファイルを保存する。
    function makeTempFile($keyname, $rename = true) {
        $objErr = new SC_CheckError();
        $cnt = 0;
        $arrKeyname = array_flip($this->keyname);
        
        if(!($_FILES[$keyname]['size'] > 0)) {
            $objErr->arrErr[$keyname] = "※ " . $this->disp_name[$arrKeyname[$keyname]] . "がアップロードされていません。<br />";
        } else {
            foreach($this->keyname as $val) {
                // 一致したキーのファイルに情報を保存する。
                if ($val == $keyname) {
                    // 拡張子チェック
                    $objErr->doFunc(array($this->disp_name[$cnt], $keyname, $this->arrExt[$cnt]), array("FILE_EXT_CHECK"));
                    // ファイルサイズチェック
                    $objErr->doFunc(array($this->disp_name[$cnt], $keyname, $this->size[$cnt]), array("FILE_SIZE_CHECK"));
                    // エラーがない場合
                    if(!isset($objErr->arrErr[$keyname])) {
                        // 画像ファイルの場合
                        if($this->image[$cnt]) {
                            $this->temp_file[$cnt] = $this->makeThumb($_FILES[$keyname]['tmp_name'], $this->width[$cnt], $this->height[$cnt]);
                        // 画像ファイル以外の場合
                        } else {
                            // 一意なファイル名を作成する。
                            if($rename) {
                                $uniqname = date("mdHi") . "_" . uniqid("").".";
                                $this->temp_file[$cnt] = ereg_replace("^.*\.",$uniqname, $_FILES[$keyname]['name']);
                            } else {
                                $this->temp_file[$cnt] = $_FILES[$keyname]['name'];    
                            }
                            $result  = copy($_FILES[$keyname]['tmp_name'], $this->temp_dir. "/". $this->temp_file[$cnt]);
                            gfPrintLog($_FILES[$keyname]['name']." -> ".$this->temp_dir. "/". $this->temp_file[$cnt]);
                        }
                    }
                }
                $cnt++;
            }
        }
        return $objErr->arrErr[$keyname];
    }

    // 画像を削除する。
    function deleteFile($keyname) {
        $objImage = new SC_Image($this->temp_dir);
        $cnt = 0;
        foreach($this->keyname as $val) {
            if ($val == $keyname) {
                // 一時ファイルの場合削除する。
                if($this->temp_file[$cnt] != "") {
                    // 負荷分散時はすべてのサーバから削除
                    if($this->multi_web_server_mode === true) {
                        $this->ftpDeleteFile($this->ftp_temp_dir . $this->temp_file[$cnt]);
                    } else {
                        $objImage->deleteImage($this->temp_file[$cnt], $this->save_dir);
                    }
                }
                $this->temp_file[$cnt] = "";
                $this->save_file[$cnt] = "";
            }
            $cnt++;
        }
    }
    
    // 一時ファイルパスを取得する。
    function getTempFilePath($keyname) {
        $cnt = 0;
        $filepath = "";
        foreach($this->keyname as $val) {
            if ($val == $keyname) {
                if($this->temp_file[$cnt] != "") {
                    $filepath = $this->temp_dir . "/" . $this->temp_file[$cnt];
                }
            }
            $cnt++;
        }
        return $filepath;
    }
    
    // 一時ファイルを保存ディレクトリに移す
    function moveTempFile() {
        $cnt = 0;
        $objImage = new SC_Image($this->temp_dir);
        
        foreach($this->keyname as $val) {
            if($this->temp_file[$cnt] != "") {
                // 負荷分散時はすべてのサーバで移動を実行
                if($this->multi_web_server_mode === true) {
                    $dist_path = $this->ftp_save_dir . $this->temp_file[$cnt];
                    $src_path = $this->temp_dir . $this->temp_file[$cnt];
                    $this->ftpMoveFile($dist_path, $src_path);
                } else {
                    $objImage->moveTempImage($this->temp_file[$cnt], $this->save_dir);
                }
                
                // すでに保存ファイルがあった場合は削除する。
                if($this->save_file[$cnt] != "" && !ereg("^sub/", $this->save_file[$cnt])) {
                    // 負荷分散時はすべてのサーバでファイル削除
                    if($this->multi_web_server_mode === true) {
                        $this->ftpDeleteFile($this->ftp_save_dir . $this->save_file[$cnt]);
                    } else {
                        $objImage->deleteImage($this->save_file[$cnt], $this->save_dir);
                    }
                }
            }
            $cnt++;
        }
    }
    
    // HIDDEN用のファイル名配列を返す
    function getHiddenFileList() {
        $cnt = 0;
        foreach($this->keyname as $val) {
            if($this->temp_file[$cnt] != "") {
                $arrRet["temp_" . $val] = $this->temp_file[$cnt];
            }
            if($this->save_file[$cnt] != "") {
                $arrRet["save_" . $val] = $this->save_file[$cnt];
            }
            $cnt++; 
        }
        return $arrRet;
    }
    
    // HIDDENで送られてきたファイル名を取得する
    function setHiddenFileList($arrPOST) {
        $cnt = 0;
        foreach($this->keyname as $val) {
            $key = "temp_" . $val;
            if($arrPOST[$key] != "") {
                $this->temp_file[$cnt] = $arrPOST[$key];
            }
            $key = "save_" . $val;
            if($arrPOST[$key] != "") {
                $this->save_file[$cnt] = $arrPOST[$key];
            }
            $cnt++;
        }
    }
    
    // フォームに渡す用のファイル情報配列を返す
    function getFormFileList($temp_url, $save_url, $real_size = false) {

        $cnt = 0;
        foreach($this->keyname as $val) {
            if($this->temp_file[$cnt] != "") {
                // ファイルパスチェック(パスのスラッシュ/が連続しないようにする。)
                if(ereg("/$", $temp_url)) {
                    $arrRet[$val]['filepath'] = $temp_url . $this->temp_file[$cnt];
                } else {
                    $arrRet[$val]['filepath'] = $temp_url . "/" . $this->temp_file[$cnt];
                }
                $arrRet[$val]['real_filepath'] = $this->temp_dir . $this->temp_file[$cnt];
            } elseif ($this->save_file[$cnt] != "") {
                // ファイルパスチェック(パスのスラッシュ/が連続しないようにする。)
                if(ereg("/$", $save_url)) {
                    $arrRet[$val]['filepath'] = $save_url . $this->save_file[$cnt];
                } else {
                    $arrRet[$val]['filepath'] = $save_url . "/" . $this->save_file[$cnt];
                }
                $arrRet[$val]['real_filepath'] = $this->save_dir . $this->save_file[$cnt];
            }
            if($arrRet[$val]['filepath'] != "") {
                if($real_size){
                    if(is_file($arrRet[$val]['real_filepath'])) {
                        list($width, $height) = getimagesize($arrRet[$val]['real_filepath']);
                    }
                    // ファイル横幅
                    $arrRet[$val]['width'] = $width;
                    // ファイル縦幅
                    $arrRet[$val]['height'] = $height;
                }else{
                    // ファイル横幅
                    $arrRet[$val]['width'] = $this->width[$cnt];
                    // ファイル縦幅
                    $arrRet[$val]['height'] = $this->height[$cnt];
                }
                // 表示名
                $arrRet[$val]['disp_name'] = $this->disp_name[$cnt];
            }
            $cnt++;
        }
        return $arrRet;
    }
    
    // DB保存用のファイル名配列を返す
    function getDBFileList() {
        $cnt = 0;
        foreach($this->keyname as $val) {
            if($this->temp_file[$cnt] != "") {
                $arrRet[$val] = $this->temp_file[$cnt];
            } else  {
                $arrRet[$val] = $this->save_file[$cnt];
            }
            $cnt++;
        }
        return $arrRet;
    }
    
    // DBで保存されたファイル名配列をセットする
    function setDBFileList($arrVal) {
        $cnt = 0;
        foreach($this->keyname as $val) {
            if($arrVal[$val] != "") {
                $this->save_file[$cnt] = $arrVal[$val];
            }
            $cnt++; 
        }
    }
    
    // 画像をセットする
    function setDBImageList($arrVal) {
        $cnt = 0;
        foreach($this->keyname as $val) {
            if($arrVal[$val] != "" && $val == 'tv_products_image') {
                $this->save_file[$cnt] = $arrVal[$val];
            }
            $cnt++; 
        }
    }
    
    // DB上のファイルの内削除要求があったファイルを削除する。 
    function deleteDBFile($arrVal) {
        $objImage = new SC_Image($this->temp_dir);
        $cnt = 0;
        foreach($this->keyname as $val) {
            if($arrVal[$val] != "") {
                if($this->save_file[$cnt] == "" && !ereg("^sub/", $arrVal[$val])) {
                    
                    // 負荷分散時はすべてのサーバでファイル削除
                    if($this->multi_web_server_mode === true) {
                        $this->ftpDeleteFile($this->ftp_save_dir . $arrVal[$val]);
                    } else {
                        $objImage->deleteImage($arrVal[$val], $this->save_dir);
                    }
                }
            }
            $cnt++; 
        }
    }
    
    // 必須判定
    function checkEXISTS($keyname = "") {
        $cnt = 0;
        $arrRet = array();
        foreach($this->keyname as $val) {
            if($val == $keyname || $keyname == "") {
                // 必須であればエラーチェック
                if ($this->necessary[$cnt] == true) {
                    if($this->save_file[$cnt] == "" && $this->temp_file[$cnt] == "") {
                        $arrRet[$val] = "※ " . $this->disp_name[$cnt] . "がアップロードされていません。<br>";
                    }
                }
            }
            $cnt++;
        }
        return $arrRet;
    }
        
    // 拡大率を指定して画像保存
    function saveResizeImage($keyname, $to_w, $to_h) {
        $path = "";
        
        // keynameの添付ファイルを取得
        $arrImageKey = array_flip($this->keyname);
        $file = $this->temp_file[$arrImageKey[$keyname]];
        $filepath = $this->temp_dir . $file;
        
        $path = $this->makeThumb($filepath, $to_w, $to_h);
        
        // ファイル名だけ返す
        return basename($path);
    }

    /**         
     * ファイルを全てのWEBサーバへコピー
     *
     * @param string $dst_path コピー先ファイルパス(相対パス)
     * @param string $src_path コピー元ファイルパス(絶対パス)
     * @return void
     */
    function ftpMoveFile($dst_path, $src_path) {
        global $arrWEB_SERVERS;

        // 全てのサーバにファイルをコピーする
        foreach($arrWEB_SERVERS as $array) {
            sfFtpCopy($array['host'], $array['user'], $array['pass'], $dst_path, $src_path);
        }
        // 移動後はファイルを削除
        @unlink($src_path);
    }

    /**         
     * ファイルを全てのWEBサーバ上から削除
     *
     * @param string $dst_path コピー先ファイルパス(相対パス)
     * @return void
     */
    function ftpDeleteFile($dst_path) {
        global $arrWEB_SERVERS;

        // 全てのサーバにファイルをコピーする
        foreach($arrWEB_SERVERS as $array) {
            sfFtpDelete($array['host'], $array['user'], $array['pass'], $dst_path);
        }
    }


    /**
     * FTP用ファイル一時格納ディレクトリ作成
     *
     * @param string $dir 作成ディレクトリ
     * @return string $ftp_temp_dir 作成一時格納ディレクトリパス
     */
    function makeFtpTempDir($dir) {
        // FTP用ファイル一時格納用ディレクトリ
        $ftp_temp_dir = $this->temp_dir . "ftp_temp/";
        // ディレクトリが存在しなかったら作成
        if(!file_exists($ftp_temp_dir)) {
            mkdir($ftp_temp_dir);
            chmod($ftp_temp_dir, 0777);
        }
        
        return $ftp_temp_dir;
    }
}
?>