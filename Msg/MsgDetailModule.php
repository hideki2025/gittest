<?php

namespace App\Controller\Msg;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\DependencyInjection\ContainerInterface as Container;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;

use Twig\Environment;

use App\Controller\Common\CommonModule;
use App\Controller\Common\ComboModule;
use App\Controller\Common\AttachedModule;
use App\Controller\Common\BreadCrumbModule;
use App\Controller\Parts\PartsModule;
use App\Controller\Parts\PartsMygroupModule;
use App\Controller\Parts\PartsLinkModule;
use App\Controller\Common\LogModule;
use App\Controller\Msg\MsgDetailFuncModule;

class MsgDetailModule{

	private $dbs;
	private $my;
	private $rgb_f;
	private $user;

	private $logger;
	
	const cond_id = 'msg_d';
	const pageMax = 20;
//2025-01-09追加([返信関連順]用)
	const pageMax_reply = 10;

    public function __construct(ManagerRegistry $doctrine
    							,private readonly Security $security
			    				,private readonly UrlGeneratorInterface $router
    							,private RequestStack $requestStack
    							,private LoggerInterface $logging
			    				,private readonly CommonModule $cmn
    							,private readonly ComboModule $cbms
								,private readonly AttachedModule $atm
								,private readonly BreadCrumbModule $bm
    							,private readonly PartsModule $ptm
								,private readonly PartsMygroupModule $pmm
								,private readonly PartsLinkModule $plm
			    				,private readonly MsgDetailFuncModule $mdfm
								,private readonly LogModule $lgm
    							,private readonly Environment $twig){
		$this->dbs = $doctrine->getConnection('remote');

		$this->my = $doctrine->getConnection('local');
 		$this->user = $security->getUser();
		$this->logger = $logging;
    }

 	//初期処理
	public function init(){
//$this->logger->warning("msg-init");

		$msg_d = $this->requestStack->getSession()->get('msg_d');

		$id = "msg_detail";

		$mode = $msg_d["mode"];
		$msg_no = $msg_d["msg_no"];

		$title = $msg_d["title"];

//2025-06-18追加
		$bm_id = $msg_d["bm_id"];

		$folder_id = "";
		$kokyaku_cd = "";
		//$link_yotei_no = "";
		if ($msg_d["ndata"] != "_") {
			$ndata = explode(":",$msg_d["ndata"]);
			$folder_id = $ndata[0];
			$kokyaku_cd = $ndata[1];
			
			//$link_yotei_no = $ndata[2];
		}

		//$combo_list = $this->cbms->combo_list_create(self::cond_id);
//2025-06-18変更($bm_id追加)
		$data = $this->mdfm->msg_detail_get($mode,$msg_no,$folder_id,$kokyaku_cd);
		switch ($mode) {
			case "view":
			case "edit":
				$twig = 'msg/msg_detail.html.twig';
				break;
		}

//2025-06-18追加
		$data["bm_id"] = $bm_id;

		//直前のパスの取得
//2025-06-11削除
//		$back_path = $this->bm->getBbreadCrumbPath(self::cond_id);

//2025-03-21追加(dataを退避)
		if ($mode == "view") {
			$this->requestStack->getSession()->set('msg-data',$data);

// 2025-03-30追加(ログ出力) -----------
			$para_l = [];
			$para_l["action"] = "閲覧";
			$para_l["subject"] = $data["msg"]["msg_title"];
			$para_l["msg_no"] = $data["msg"]["msg_no"];
			$this->fn_log_out($para_l);
//-------------------------------------
		}

//$this->logger->warning("msg-init-end");

		return new Response($this->twig->render($twig,$data));
    }

//2025-06-11移動(MsgDetailFuncModule)へ
	//メッセージ情報の取得

	//メッセージ詳細 更新処理
	public function msg_detail_update($frm,$msg_no,$member_list,$save_mode) {

	// 添付ファイル処理
	//***************************************************************************************
	//2023-12-20変更(引数[tmp_dir]追加)
		$ret = $this->atm->fileSizeChk($frm['tmp_dir']);
		if ($ret != "") {
			return ['status' => false,"err_msg" => $ret ];
		}
	//***************************************************************************************

	//添付ファイル名の取得
		$tmp_dir = $frm['tmp_dir'];

		$temp_name = [];
		foreach(glob($tmp_dir."/*.*") as $file){
			$fileinfo = pathinfo($file);
			$temp_name[] = $fileinfo["basename"];
		}
		$file_name = null;
		if (count($temp_name) > 0) {
			$file_name = implode(", ", $temp_name);
		}

		$user_id = $this->user->getUserId();
//2025-02-20追加		
		if ($save_mode == "backup"){
			$table = "wt_msg";
		} else {
			$table = "t_msg";
		}
		$update_time = new \DateTime();

		$msg_no = $frm['txt_msg_no'];

//2025-06-21追加
		$new_record = 0;
		
		//存在チェック
//2025-02-20追加
		if ($save_mode !== "backup"){
			$eof_flg = false;
			if ($frm["txt_new_record"] == 0) {
				//訂正の場合は、存在チェック
				$sql  = "select *";
				$sql .= " from [".$table."]";
				$sql .= " where [msg_no] = :msg_no";
				$paramVal = ["msg_no" => $msg_no ];
				$rst = $this->dbs->fetchAssociative($sql,$paramVal);
				if ($rst == false) {
					$eof_flg = true;
				}
			} else {
				$eof_flg = true;
			}

			//新規の場合、[msg_no]のみ作成
			if ($eof_flg) {
				//トランザクション(開始)
				$rst = $this->dbs->beginTransaction();

				//msg_noの最大を再取得
				$sql = "select max([msg_no]) As [msg_no] from {$table}";
				$rst = $this->dbs->fetchAssociative($sql);
				$msg_no = 1;
				if ($rst !== false) {
					$msg_no = $rst["msg_no"] + 1;
				}

				//新規の場合は、追加
				$dataVal = ['msg_no'  => $msg_no ];
				$rst = $this->dbs->insert($table,$dataVal);

				//トランザクション(終了)
				$rst = $this->dbs->commit();
//2025-06-21追加
				$new_record = 1;
			}
		} else {
			//バックアップの時、存在チェック
			$sql  = "select msg_no";
			$sql .= " from [".$table."]";
			$sql .= " where [msg_no] = :msg_no";
			$paramVal = ["msg_no" => $msg_no ];
			$rst = $this->dbs->fetchAssociative($sql,$paramVal);
			if ($rst == false) {
				//存在しない時、追加
				$dataVal = ['msg_no'  => $msg_no ];
				$rst = $this->dbs->insert($table,$dataVal);
			}
			$eof_flg = true;
		}
		//データセット
		$dataVal  = [];
		$dataVal += ['msg_title'        	=> $frm["txt_msg_title"] ];
//2025-08-11変更
		//本文「添付ファイル」パスを保存用に変更
		$edit_memo = $this->atm->fn_memo_save_path_set($frm["txt_memo"],$msg_no);
		$dataVal += ['memo'        			=> $edit_memo ];

//2025-08-10追加(本文検索用テキスト)
		$dataVal += ['s_memo'        		=> strip_tags($edit_memo) ];
		if ($eof_flg) {
			$dataVal += ['from_user_id'     	=> $this->cmn->fn_nz($frm["txt_from_user_id"],null) ];
			$dataVal += ['from_by'          	=> $this->cmn->fn_nz($frm["txt_from_by"],null) ];
			$dataVal += ['from_at'          	=> $update_time->format('Y-m-d H:i') ];
		}
		$dataVal += ['folder_id'        	=> $frm["cbo_folder"] ];
		
		if (!empty($frm["cbo_category"])) {
			$dataVal += ['category_id'      => implode(",", $frm["cbo_category"]) ];
		}
		if (!empty($frm["cbo_thema"])) {
			$dataVal += ['thema_id'        	=> implode(",", $frm["cbo_thema"]) ];
		}
		if (!empty($frm["cbo_state"])) {
			$dataVal += ['state_id'         => implode(",", $frm["cbo_state"]) ];
		}
		$dataVal += ['attached_file'    	=> $file_name ];
		$dataVal += ['kokyaku_cd'       	=> $this->cmn->fn_nz($frm["txt_kokyaku_cd"],null) ];

//2025-05-18追加(案件から遷移してきた場合、[案件NO]の保存)
		$anken_link_msg_no = 0;
		$upd_anken_no = 0;
		if ($this->requestStack->getSession()->has('upd_link_no')){
			$upd_anken_no = $this->requestStack->getSession()->get('upd_link_no');
		}

//リンク先anken_no更新
//2024-10-25修正
//		$anken_no = 0;
//2025-02-20追加		
		if ($save_mode !== "backup"){
			if ($this->requestStack->getSession()->has('upd_link_no')){
				$this->requestStack->getSession()->remove('upd_link_no');
			}
		}
//2024-10-27変更
//		if ($frm["cbo_msg_anken"] != "") {
			$dataVal += ["anken_no"				=> $frm["cbo_msg_anken"] ];
//		}
//2025-06-02削除
//		$dataVal += ['link_msg_no'          => $this->cmn->fn_nz($frm["txt_link_msg_no"],null)  ];
//		$dataVal += ['link_comment_no'      => $this->cmn->fn_nz($frm["txt_link_comment_no"],null)  ];

//2025-06-19追加
		if ($frm["txt_link_yotei_no"] != "") {
			$dataVal += ['link_yotei_no' => $frm["txt_link_yotei_no"] ];
		}

		//save_mode: draft:下書き save:投稿
		$is_draft = ($save_mode == "draft") ? 1 : 0;
		$dataVal += ["is_draft"				=> $is_draft ];
		$dataVal += ["is_delete"			=> 0 ];

		$chk_is_comment_allow = (empty($frm["chk_is_comment_allow"])) ? 0 : 1;
		$dataVal += ['is_comment_allow'    	=> $chk_is_comment_allow ];
		$chk_is_reaction_allow = (empty($frm["chk_is_reaction_allow"])) ? 0 : 1;
		$dataVal += ['is_reaction_allow'    => $chk_is_reaction_allow ];
		$chk_is_comment_allow = (empty($frm["chk_is_comment_allow"])) ? 0 : 1;

		// 投稿日時
		$is_reserve = 0;
		$reserve_ymd = null;
		if (!empty($frm['chk_is_reserve_next'])) {
			$is_reserve = 1;
			$reserve_ymd = $frm['txt_reserve_at'];
		}
		$dataVal += ["is_reserve" => $is_reserve ];
		$dataVal += ["reserve_at" => $reserve_ymd ];
		if ($eof_flg) {
			$dataVal += ['created_at'       => $update_time->format('Y-m-d H:i') ];
			$dataVal += ['created_user_id'  => $user_id ];
			$dataVal += ['modified_at'      => null ];
			$dataVal += ['modified_user_id' => null ];
		} else {
			$dataVal += ['modified_at'      => $update_time->format('Y-m-d H:i') ];
			$dataVal += ['modified_user_id' => $user_id ];
		}
		
		//Keyデータセット
		$paramVal = ['msg_no' => $msg_no ];
		$rst = $this->dbs->update($table,$dataVal,$paramVal);

//2025-06-02追加
		//(メッセージコメントデータ更新)リンク元
		if ($frm["txt_link_kind"] == "comment") {
			$table_action = "t_msg_comment";
			$dataVal  = ['link_msg_no' => $msg_no ];
			$dataVal += ['modified_at' => $update_time->format('Y-m-d H:i') ];
			$dataVal += ['modified_user_id' => $user_id ];

			$paramVal  = ['msg_no'     => $frm["txt_link_msg_no"] ];
			$paramVal += ['comment_no' => $frm["txt_link_comment_no"] ];
			$ret = $this->dbs->update($table_action,$dataVal,$paramVal);
		}

//2025-02-20追加		
		if ($save_mode == "backup"){
			return ["status" => true ,"data" => [] ];
		}

		//スケジュールへリンク更新
		if ($frm["txt_link_yotei_no"] != "" && ($new_record == 1)){
			$table_yotei = "t_yotei";
			
			$paramVal = ['yotei_no' => $frm["txt_link_yotei_no"] ];
			$dataVal = ['link_msg_no'  => $msg_no  ];
//2025-06-21削除
//2024-12-05追加
//			$dataVal += ['kokyaku_cd'  => $this->cmn->fn_nz($frm["txt_kokyaku_cd"],null) ];

			$ret = $this->dbs->update($table_yotei,$dataVal,$paramVal);
		}

		//***************************************
		//メッセージ詳細(ユーザー)登録
		//***************************************
		$table_user = "t_msg_user";

		$sql = "delete ";
		$sql .= " from {$table_user}";
		$sql .= " Where msg_no = :msg_no";
		$sql .= "   and comment_no is null";
		$paramVal = ["msg_no" => $msg_no ];
		$ret = $this->dbs->executeUpdate($sql,$paramVal);

		$sankasha_list = $member_list["sankasha"];
		if ($sankasha_list != []) {
			for ($i = 0;$i < count($sankasha_list);$i++) {
				$dataVal  = ["msg_no"     => $msg_no ];
				$dataVal += ["user_type"  => $sankasha_list[$i]['user_type'] ];
				$dataVal += ["user_id" 	  => $sankasha_list[$i]['user_id'] ];
				$dataVal += ["user_role"  => $sankasha_list[$i]['user_role'] ];
				//追加実行
				$ret = $this->dbs->insert($table_user,$dataVal);				
			}
		}

		//***************************************
		//メッセージ詳細(アクション)更新
		//登録済み 同一メッセージNO を未読に更新
		//***************************************
		$table_action = "t_msg_action";

		$paramVal  = ['msg_no'     => $msg_no ];
		$paramVal += ['comment_no' => null ];
		$dataVal = ['is_read' => 0 ];

		$ret = $this->dbs->update($table_action,$dataVal,$paramVal);

//2025-03-05
		//メッセージ情報の作成
		$this->fn_msg_read_create($msg_no);

		// 添付ファイル保存処理
		// 保存フォルダの再設定
		$attached_file_dir = "../../files/attached/msg";
		$folder_msg_no = sprintf('%08d', $msg_no);
		$attached_file_dir .= "/{$folder_msg_no}";
		$this->atm->fileSave($frm['tmp_dir'],$attached_file_dir);

//2025-08-11追加
		//本文「添付ファイル」の保存
		$this->atm->fn_memo_file_save("msg", $edit_memo, $msg_no);

// 2025-03-30追加(ログ出力) -----------
		$para_l = [];
		if ($eof_flg) {
			$para_l["action"] = "新規";
		} else {
			$para_l["action"] = "更新";
		}
		$para_l["subject"] = $frm["txt_msg_title"];
		$para_l["msg_no"] = $msg_no;
		$this->fn_log_out($para_l);
//-------------------------------------

		//メッセージ再取得
		$data = $this->mdfm->msg_detail_get("view",$msg_no);
//2025-06-22追加(パンくず更新)
		$bm_cont = "";
		if ($new_record == 1) {
			$bm_name = "メッセージ詳細";
			$menu_id = "msg_detail";
			$chk = $this->bm->chkBbreadCrumbPath("msg_new","all");
			if ($chk == 1){
				$bm_name = "メッセージ表示";
				$menu_id = "msg_new";
			}
			$view_path = $this->router->generate('app_msg_detail',
						['mode' => 'view','msg_no' => $msg_no,'caller'=>"-" ]);
			$ret = $this->bm->setBbreadCrumbPara($menu_id,"url",$view_path);

			$ret = $this->bm->setBbreadCrumbPara($menu_id,"name",$bm_name);
			$bm_cont = $this->bm->setBbreadCrumb_chg($menu_id,$view_path);
		}
		return ["status" => true ,"data" => $data, "bm_cont" => $bm_cont ];

	}

	//メッセージ情報作成
	private function fn_msg_read_create($msg_no) {

		//テンポラリーテーブル初期設定
		$temp_action = "#temp_action";
		$sql  = "IF EXISTS (select name from tempdb..sysobjects";
		$sql  .= " WHERE id = OBJECT_ID('tempdb..".$temp_action."') and type = 'U')";
		$sql  .= " drop table [{$temp_action}];";
		
		//テンポラリーテーブル作成
		$sql1 = "
select
	a.msg_no
	,a.user_id
into {$temp_action}
from (
select s1.msg_no
		,user_id =  s12.soshiki_user_id
from t_msg s1
	inner join t_msg_user s11
		on s11.msg_no = s1.msg_no
	inner join m_soshiki_user s12
		on s12.soshiki_group_id = s11.user_id
where s1.msg_no = :msg_no1
  and s11.user_type=1
  and s11.comment_no is null

union all 

select s2.msg_no
		,s21.user_id
from t_msg s2
	left join t_msg_user s21
		on s21.msg_no = s2.msg_no
where s2.msg_no = :msg_no2
  and s21.user_type=2
  and s21.comment_no is null) a
group by a.msg_no,a.user_id;";

		$paramVal  = ['msg_no1' => $msg_no ];
		$paramVal += ['msg_no2' => $msg_no ];

		//既存テーブルより削除データのクリア
		$sql2  = "
delete a
from t_msg_action a
  left join {$temp_action} b
     on  b.msg_no = a.msg_no
     and b.user_id = a.user_id
where a.msg_no = :msg_no3
  and a.comment_no is null
  and b.user_id is null;";
		$paramVal += ['msg_no3' => $msg_no ];

		//既存テーブルに存在しないデータの追加
		$sql3  = "
insert into t_msg_action
 (user_id,msg_no,comment_no,is_read)
select a.user_id
      ,a.msg_no
      ,comment_no = null
      ,is_read = 0
from {$temp_action} a
  left join t_msg_action b
     on  b.msg_no = a.msg_no
     and b.user_id = a.user_id
     and b.comment_no is null
where b.user_id is null;";

		$sql .= $sql1.$sql2.$sql3;
		$ret = $this->dbs->executeUpdate($sql,$paramVal);

		return false;

	}

//2025-05-17変更 別モジュールへ移動

// 2025-03-30追加 アクセスログ出力
	private function fn_log_out($para_l) {

		$para  = [ "category"   => isset($para_l["comment_no"])  ? "comment"             : "msg" ];
		$para += [ "action"     => isset($para_l["action"])      ? $para_l["action"]     : null ];
		$para += [ "subject"    => isset($para_l["subject"])     ? $para_l["subject"]    : null ];
		$para += [ "msg_no"     => $para_l["msg_no"] ];
		$para += [ "comment_no" => isset($para_l["comment_no"])  ? $para_l["comment_no"] : null ];
		$this->lgm->access_log_out($para);

	}

	public function getTrainingMsgMember($ankenNo)
	{
		return $this->pmm->fn_anken_member_get($ankenNo);
	}

}
