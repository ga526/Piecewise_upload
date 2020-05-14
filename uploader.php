<?php

class uploader
{
    private $uploaderDir = "./upload";
    private $redis;

    public function __construct()
    {
        $this->redis = new Redis();
        $this->redis->connect("192.168.10.130", '12342');
        $this->redis->auth('2ws#$%RFE');
        $this->redis->select(9);
    }

    //code 1 全部上传完成   200 分片上传完成  400 分片需要重新上传
    public function upload()
    {
        //1.记录文件分片号,
        $totalKey = $_REQUEST["fileName"] . ":" . $_REQUEST["totalSize"];
        if (empty($_REQUEST["fileMD5"])) {
            exit(json_encode(["code" => 0, "分片上传失败,缺少文件md5"]));
        }
        if ($this->redis->get($totalKey)) {
            exit(json_encode(["code" => 1, "msg" => "文件已经上传过"]));
        }
        $this->redis->set($totalKey, 0);//上传状态
        //将文件保存到临时文件夹下
        $totalListKey = $_REQUEST["fileMD5"] . ":list:";
        //待清除redis 列表
        //2.判断当前分片是否已上传
        $res = $this->hasUploadedChunk($totalListKey, $_REQUEST['index']);
        if (!$res) {
            //data 分片号
            exit(json_encode(["code" => 1, "msg" => "文件已经上传过"]));
        }

        //2.判断分片是否正确(大小)
        $res = $this->checkChunk();
        if (!$res) {
            //data 分片号
            exit(json_encode(["code" => 400, "msg" => "分片错误,需要重传", "data" => $_REQUEST["index"]]));
        }

        //3.存储分片
        $res = $this->saveChunk($totalListKey);
        if ($res["code"] != 1) {
            exit(json_encode($res));
        }

        //4.重组文件
        $data = $this->reMakeFile($totalListKey);
        if($data["code"] != 1){
            return json_encode($data);
        }

        //5.移除redis数据
        $this->clearRedis($totalKey, $totalListKey);
        if ($data["data"] == $_REQUEST["fileMD5"]) {
            exit(json_encode(["code" => 1, "msg" => "上传完成"]));
        } else {
            $this ->redis ->del($totalKey);
            exit(json_encode(["code" => 0, "msg" => "md5 不匹配，php md5：{$data["data"]}, js md5 {$_REQUEST["fileMD5"]}", "file" => $data["file"]]));
        }
    }

    //判断分片是否寂静上传过
    private function hasUploadedChunk($totalListKey)
    {
        return !$this->redis->sIsMember($totalListKey, $_REQUEST["index"]);
    }

    //判断分片大小是否等于当前文件大小
    private function checkChunk()
    {
        if ($_REQUEST["totalChunk"] == $_REQUEST["index"]) {
            //如果是最后一片
            $mode = $_REQUEST["chunkSize"] == 0 ? 0 : $_REQUEST["totalSize"] % $_REQUEST["chunkSize"];
            if ($mode == $_FILES["data"]["size"] || $mode == 0) {
                return true;
            } else {
                return false;
            }
        } else {
            if ($_REQUEST["chunkSize"] == $_FILES["data"]["size"]) {
                return true;
            } else {
                return false;
            }
        }
    }

    //存储分片
    private function saveChunk($totalListKey)
    {
        $descName = implode("_", explode(":", $totalListKey));
        !is_dir($this->uploaderDir) && mkdir($this->uploaderDir, 777, true);
        $res = move_uploaded_file($_FILES['data']['tmp_name'], $this->uploaderDir . "/" . $descName . "_" . $_REQUEST["index"]);
        if (!$res) return [
            "code" => 400,
            "msg" => "文件移动失败",
            "data" => $_REQUEST["index"]
        ];
        $this->redis->sadd($totalListKey, $_REQUEST["index"]);
        return [
            "code" => 1
        ];
    }

    //重组文件
    private function reMakeFile($totalListKey)
    {
        $len = $this->redis->scard($totalListKey);
        if ($len != $_REQUEST["totalChunk"]) {
            return ["code" => 200, "msg" => "index " . $_REQUEST["index"] . " len $len, totalChunk {$_REQUEST["totalChunk"]} 分片上传完成"];
        }
        try {
            $source = fopen($this->uploaderDir . "/" . $_REQUEST["fileName"], "w+b");
            if (!flock($source, LOCK_EX)) {
                return ["code" => 0, "msg" => "上传文件被占用 !"];
            }

            for ($i = 0; $i < $len; $i++) {
                $totalListKey = implode("_", explode(":", $totalListKey));
                $openFileName = $this->uploaderDir . "/" . $totalListKey . "_" . ($i + 1);
                $readsource = fopen($openFileName, "r+b");
                while ($content = fread($readsource, 1024)) {
                    fwrite($source, $content);
                }
                fclose($readsource);
                unlink($openFileName);
            }
            fclose($source);
        } catch (Exception $e) {
            exit($e->getMessage());
        }
        return ["code" => 1, "data" => md5_file($this->uploaderDir . "/" . $_REQUEST["fileName"]), "file" => $this->uploaderDir . "/" . $_REQUEST["fileName"]];
    }

    private function clearRedis($totalKey, $totalList)
    {
        $this->redis->set($totalKey, 1);
        $list = $this->redis->sMembers($totalList);
        if ($list) {
            foreach ($list as $v) {
                $this->redis->del($v);
            }
        }
        $this->redis->del($totalList);
    }
}


(new uploader())->upload();
