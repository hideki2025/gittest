<?php

namespace App\Controller\Msg;

use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Doctrine\Persistence\ManagerRegistry;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Psr\Log\LoggerInterface;

class MsgReserveModule{

	private $dbs;

//	private $msg;
	
    public function __construct(ManagerRegistry $doctrine
    				,private readonly UrlGeneratorInterface $router
    				,private readonly LoggerInterface $logger
    ){

		$this->dbs = $doctrine->getConnection('remote');

    }

	public function fn_msg_reserve_chk() {

		//現在日時の取得
		$date_time = date("Y-m-d H:i:s");
		
		// 投稿予約データが存在するかチェック
		$sql  = " select";
		$sql .= " msg_no";
		$sql .= ",reserve_at";
		$sql .= " from t_msg";
		$sql .= " where isnull(is_delete,0) = :is_delete";
		$sql .= "   and isnull(is_draft,0) = :is_draft";
		$sql .= "   and isnull(is_reserve,0) = :is_reserve";
		$sql .= "   and reserve_at < :reserve_at";
		$sql .= " order by reserve_at,id";
		$paramVal  = ["is_delete"  => 0 ];				//0:投稿中データ
		$paramVal += ["is_draft"   => 0 ];				//0:投稿データ
		$paramVal += ["is_reserve" => 1 ];				//1:投稿予約データ
		$paramVal += ["reserve_at" => $date_time ];	//投稿予約日時
		$rst = $this->dbs->fetchAllAssociative($sql,$paramVal);

		for ($i = 0;$i < count($rst);$i++) {
			// 投稿予約データの通知設定

			// 投稿予約データは、"投稿データ"に変更
			$table = "t_msg";
			$dataVal  = ["is_reserve"  => 0    ];	//0:投稿データに設定
			$dataVal += ["reserve_at"  => null ];	//投稿予約日時のクリア
			//パラメータ設定
			$paramKey  = ["msg_no"     => $rst[$i]['msg_no'] ];
			$paramKey += ["is_reserve" => 1 ];
			//更新実行
			$ret = $this->dbs->update($table,$dataVal,$paramKey);

			$msg = "メッセージ予約チェック メッセージNO:" . $rst[$i]['msg_no'];
			$this->logger->warning($msg);
		}

		return false;
	}

}