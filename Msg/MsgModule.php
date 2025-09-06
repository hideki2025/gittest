<?php

namespace App\Controller\Msg;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;
use Doctrine\Persistence\ManagerRegistry;

use App\Controller\Common\BreadCrumbModule;
use App\Controller\Common\CommonModule;
use App\Controller\Common\ComboModule;
use App\Controller\Common\FilterModule;
use App\Controller\Common\DtctlModule;
use App\Controller\Parts\PartsModule;

class MsgModule{

	private $dbs;

	const cond_id = 'msg_list';

    public function __construct(ManagerRegistry $doctrine
    				,private readonly Environment $twig
    				,private readonly UrlGeneratorInterface $router
					,private RequestStack $requestStack
					,private readonly Security $security
    				,private readonly CommonModule $cmn
    				,private readonly ComboModule $cbms
					,private readonly BreadCrumbModule $bm
    				,private readonly DtctlModule $dtctl
    				,private readonly PartsModule $ptm
    				,private readonly FilterModule $filter_s)
    {
		$this->dbs = $doctrine->getConnection('remote');
 		$this->user = $security->getUser();
    }

	public function init($kokyaku_cd,$filter_mode = "list",$filter_midoku = ""){

		$back_path = "";
		if ($kokyaku_cd !== "0") {
			//直前のパスを取得
			$msg_form_id = "msg_list";
			$back_path = $this->bm->getBbreadCrumbPath($msg_form_id);
		}
		//コンボリスト作成
		$combo_list = $this->cbms->combo_list_create(self::cond_id);

		//初期値取得
		$cond_para = $this->fn_ex_condition_get($kokyaku_cd,$filter_mode);
		//メッセージデータ、宛先フォーム作成
		$share_form = $this->twig->render("parts/mdl_role_edit.twig",["mode" => "msg"]);

		//データリスト取得
		$combo_data = [];
		//[組織グループ]
		$data = $this->cbms->combo_create("soshiki_group");
		// データリスト
		$combo_data["soshiki_group"] = json_encode($data["record"]);
		//[Myグループ]
		$data = $this->cbms->combo_create("mygroup");
		// データリスト
		$combo_data["mygroup"] = json_encode($data["record"]);

		//左側メニュー
		$mode = "msg";
//    	$sub_src = $this->ptm->menu_get($mode);
		$para = $this->ptm->fn_folder_list_get($mode);

		$menu_id = "msg";
		$twig = 'msg/msg.html.twig';
		$caption ="メッセージ一覧";
		$ajax_path ="app_msg_ajax";
		$page_id = "msg_list";

		$parent_id = 1;
		$folder_list = $this->ptm->folder_get($mode,$parent_id);

		//新規メッセージ
		$ndata = "@1:{$kokyaku_cd}";	//"フォルダID":"顧客CD"
		$msg_new_path = $this->router->generate('app_msg_detail'
										,[
										 'mode'   => "edit"
										,'msg_no' => 0
										,'ndata'  => $ndata
										]);
//パンくず追加
		$breadcrumb = $this->requestStack->getSession()->get('bread_array');

//2025-04-15追加
		//$bread_crumb = $this->bm->getBbreadCrumbPara("msg_list");
		if ($breadcrumb !== []) {
			if (isset($breadcrumb["cond"])){
				$cond_para = $breadcrumb["cond"];

				$cond_para["dt_init"] = "init";
				//抽出条件の保存
				$ret = $this->bm->setBbreadCrumbPara(self::cond_id,"cond",$cond_para);
			}
		}

		$active_menu = $this->requestStack->getSession()->get('acitive_menu');

//2024-11-24追加
		$msg_p = "";
		if ($this->requestStack->getSession()->has('msg_p')) {
			$msg_p = $this->requestStack->getSession()->get('msg_p');
			$this->requestStack->getSession()->remove('msg_p');
		}

		// Clear the training data from the session to not interfere when creating a new message
		$this->requestStack->getSession()->set('training', null);
//2025-07-19追加(DTCTL)
		$dtctl_para = $cond_para['dtctl'];
		$teikei_id = "";
		if ($cond_para['dtctl'] != "") {
			$teikei_id = $cond_para['dtctl']["searchList"];
		}
		$combo_list_dtctl = $this->dtctl->fn_dtctl_combo_create("msg_list",$teikei_id);

		$src = [
			'mode'          => $mode
			,'menu_id'      => $menu_id
			,'page_id'      => $page_id
			,'caption'      => $caption
			,'title'        => 'メッセージ'
			,'ajax_path'    => $ajax_path
			,'combo_list'   => $combo_list
			,'cond'         => $cond_para
			,"parent_id"   	=> $parent_id
			,"folder_list" 	=> $folder_list
			,"para" 		=> $para
			,'share_form'   => $share_form
			,'combo_data'   => $combo_data
			,'msg_new_path' => $msg_new_path
			,'filter_mode'  => $filter_mode		//'top':トップ画面から 'list':一覧画面から
			,'filter_midoku'  => $filter_midoku		//'0':宛先指定 '1':未読データのみ
			,"breadcrumb"   => $breadcrumb
			,"active_menu"  => $active_menu
			,'back_path'    => $back_path
//2024-11-24追加
			,'msg_p'        => $msg_p
//2025-06-27追加
			,'combo_list_dtctl' => $combo_list_dtctl
//2025-07-19追加
			,'dtctl_para'       => $dtctl_para
			,'dtctl'            => json_encode($dtctl_para)
			];

		return new Response($this->twig->render($twig,$src));

	}
	//上段抽出条件の取得
	private function fn_ex_condition_get($kokyaku_cd,$filter_mode){

		$cond_para = [];

		$bread_crumb = $this->bm->getBbreadCrumbPara(self::cond_id);
		if ($bread_crumb !== []) {
			if (isset($bread_crumb["cond"])){
				$cond_para = $bread_crumb["cond"];
//2024-11-07追加(初期値に戻す)
//				$cond_para["search_mode"] = "0";
			}
		}

		if ($cond_para === []){
			//初期値データの取得
			$data_def = $this->fn_ex_condition_default_get($kokyaku_cd,$filter_mode);
			$keyword = $data_def["kokyaku_name"];
			if ($data_def["user_name"] != ""){
				$keyword = $data_def["user_name"];
			}

			$cond_para = [ "tokobi_from"      => $data_def["tokobi_from"]	// 投稿日(開始)
						  ,"tokobi_to"        => $data_def["tokobi_to"]		// 投稿日(終了)
						  ,"filter_item1"     => $data_def["filter_item1"]	// 検索項目1
						  ,"category"         => ""					// カテゴリ
						  ,"thema"            => ""					// テーマ
						  ,"state"            => ""					// 状態
						  ,"filter"           => $keyword		// 検索文字
						  ,"filter_item2"     => $data_def["filter_item2"]	// 検索項目2
						  ,"folder_id"        => ""					// 検索フォルダ―ID
						  ,"tokobi_from_def"  => $data_def["tokobi_from"]	// 以下初期値↓↓
						  ,"tokobi_to_def"    => $data_def["tokobi_to"]
						  ,"filter_item1_def" => $data_def["filter_item1"]
						  ,"filter_item2_def" => $data_def["filter_item2"]
//2024-11-06追加
						  ,"search_mode"      => $data_def["search_mode"]
						  ,"search_mode_def"  => $data_def["search_mode"]
//2025-04-20追加(検索)
						  ,"atesaki_fill"     => $data_def["atesaki_fill"]
//2025-07-19追加
						  ,"dtctl"            => ""
						 ];
		}

		return $cond_para;
	}

	//上段抽出条件の初期値取得
	private function fn_ex_condition_default_get($kokyaku_cd="0",$filter_mode = "list"){

		$kokyaku_name = "";
		$user_name = "";
//2024-11-06追加
		$search_mode = "0";

		if ($kokyaku_cd !== "0"){
//顧客詳細からリンクの時
			$sql  = "select a.[組合名],a.[支部名] from [M_顧客] a";
			$sql .= ' where a.[顧客CD] =:id';
			$paramVal = ["id" => $kokyaku_cd ];
			$rst = $this->dbs->fetchAssociative($sql,$paramVal);
			if ($rst !== false){
				$kokyaku_name = $this->cmn->fn_nz($rst['組合名']);
//2024-12-06追加
				$search_mode = "1";
			}
		}
		if ($filter_mode == "top"){
			$login_user_id = $this->user->getUserId();
			$sql  = "select c1.[社員名]";
			$sql .= " from [M_User] a";
			$sql .= "    left join [C_社員] c1";
			$sql .= "       on c1.[社員CD] = a.[社員CD]";
			$sql .= " where a.[ユーザーID] = :user_id";
			$paramVal = ["user_id" => $login_user_id ];
			$rst = $this->dbs->fetchAssociative($sql,$paramVal);
			if ($rst !== false){
			//宛先指定追加
				$user_name = $this->cmn->fn_nz($rst['社員名']);
//2024-12-06追加
				$search_mode = "1";
			}
		}

//2025-04-19変更　初期値ブランク
		$tokobi = [ "from" => "", "to" => "" ];

//2025-08-08 speedup対策
//2025-08-11変更(1ヶ月前に変更)
		//システム日付の取得
		$dtm_today = strtotime(date("Y/m/d"));
		$tokobi["from"] = date("Y/m/d",strtotime("-1 month",$dtm_today));

//2024-12-20追加
		if ($kokyaku_cd !== "0"){
			$tokobi = [ "from" => "", "to" => "" ];
		}

		$filter_item1  = [0,0,0];
//2025-08-08 speedup対策
//		$filter_item2  = [1,1,1,0,0,0,0];
		$filter_item2  = [1,1,0,0,0,0,0];
		if ($filter_mode == "top"){
			//宛先指定追加
			$filter_item2  = [0,0,0,0,0,1,0];
		}
		$data = [ "tokobi_from"  => $tokobi["from"]
				 ,"tokobi_to"    => $tokobi["to"]
				 ,"filter_item1" => $filter_item1
				 ,"filter_item2" => $filter_item2
				 ,"kokyaku_name" => $kokyaku_name
				 ,"user_name"    => $user_name
//2024-11-06追加
				 ,"search_mode"  => $search_mode
//2025-04-20追加
				 ,"atesaki_fill" => 1			//1:すべて
				];
		return $data;
	}

//2025-04-09変更
//メッセージ一覧の[既読更新]の対応 ($batch = 0:通常 1:既読更新)
	//メッセージ一覧データ抽出処理
	//2025-07-02追加 $download = 0:通常 1:ダウンロード時
	public function get_list_data($para,$batch=0,$download=0){

		$frm = $para["frm"];
		$filter_mode = $frm['filter_mode'];
		$filter_midoku = $frm['filter_midoku'];

//2025-04-20変更
		if ($batch == 1) {
			$length = null;
			$start = null;
			$order = null;
			$draw = null;
		} else {
			$length = $para["length"];
			$start = $para["start"];
			$order = $para["order"];
			$draw = $para["draw"];
		}

//serverSide対応
		$record_count = 0;
		$data = [];
		if ($frm["search_mode"] == "1" || $download == 1) {
			$login_user_id = $this->user->getUserId();

			if ($filter_mode == "top"){
//2025-03-09変更
//					$rst = $this->fn_top_msg_list($frm,$login_user_id);
				$target_folder_id = "";
				if (isset($frm["txt_target_folder"])) {
					$folder_id = $frm["txt_target_folder"];
					if ($folder_id != 1){
						$target_folder_id = $frm['txt_target_folder'];
					}
				}

//2025-04-12追加
				//DataTables用の並び順を結合
				$dt_order = $this->filter_s->get_datatables_order($order);
				//並び替え指定が"なし"の場合は、初期値をセット
				$dt_order = ($dt_order == "" ? " order by 7 desc" : $dt_order);

				$sql  = "exec spweb000_TopMsgList";
				$sql .= " @p_login_user_id = :login_user_id";
				$sql .= ",@p_target_folder = :target_folder";
				$sql .= ",@p_mode = :mode";
				$sql .= ",@p_midoku = :midoku";
//2025-04-12追加
				$sql .= ",@p_start = :start";
				$sql .= ",@p_length = :length";
				$sql .= ",@p_atesaki_fill = :atesaki_fill";
				$sql .= ",@p_dt_order = :dt_order";
				$sql .= ",@p_batch = :p_batch";
				$paramVal  = [ "login_user_id" => $login_user_id ];
				$paramVal += [ "target_folder" => $target_folder_id ];
				$paramVal += [ "mode"          => "msg" ];
				$paramVal += [ "midoku"        => $filter_midoku ];
//2025-04-12追加
				$paramVal += [ "start"         => strval($start) ];
				$paramVal += [ "length"        => strval($length) ];
				$paramVal += [ "atesaki_fill"  => $frm["atesaki_fill"] ];
				$paramVal += [ "dt_order"      => $dt_order ];
				$paramVal += [ "p_batch"       => $batch ];
				$types[] = SQLSRV_PARAM_IN;
				$rst = $this->dbs->fetchAllAssociative($sql, $paramVal, $types);

				if ($batch == 1) {
					return;
				}
//2025-04-12追加
				if ($rst != []){
//						$record_count = $rst[0]["record_count"];
					$record_count = $rst[0]["record_count"];
				}

//2025-04-20追加
			//上段抽出条件の保存
				$this->requestStack->getSession()->set('gw_msg/folder_id',$target_folder_id);
				//初期値データの取得
				$data_def = $this->fn_ex_condition_default_get();

				$cond_para  = ['tokobi_from_def' => $data_def["tokobi_from"] ];
				$cond_para += ['tokobi_to_def'   => $data_def["tokobi_to"] ];
				$cond_para += ['filter_item1_def'=> $data_def["filter_item1"] ];
				$cond_para += ['filter_item2_def'=> $data_def["filter_item2"] ];
				$cond_para += ['search_mode'     => $frm["search_mode"] ];
				$cond_para += ['search_mode_def' => $data_def["search_mode"] ];
				$cond_para += ['folder_id'       => $target_folder_id ];
				$cond_para += ['atesaki_fill'    => $frm["atesaki_fill"] ];

				//抽出条件の保存
				$ret = $this->bm->setBbreadCrumbPara(self::cond_id,"cond",$cond_para);
			} else{
//2025-07-02追加
				[$sql_count,$sql,$paramVal] = $this->fn_msg_msg_list($para,$login_user_id,$batch,$download);
				//serverSide対応
				$rst_all = $this->dbs->fetchAllAssociative($sql_count,$paramVal);
				if ($rst_all != []){
					$record_count = count($rst_all);
				}

				$rst = $this->dbs->fetchAllAssociative($sql,$paramVal);
				$record_count_one = count($rst);
//2025-07-02追加
				if ($download == 1) {
					return $rst;
				}
			}

			$group_msg_no = 0;
			if ($rst !== false){
				for ($i = 0;$i < count($rst);$i++){
					if ($batch == 0) {

//2025-05-04変更 共通化
						$category_name = $this->cmn->fn_category_list_cnv("category",$rst[$i]["category_id"]);
						$thema_name = $this->cmn->fn_category_list_cnv("thema",$rst[$i]["thema_id"]);
						$state_name = $this->cmn->fn_category_list_cnv("state",$rst[$i]["state_id"]);


						if ($filter_mode == "top"){
							$updated_at = $rst[$i]["update_at"];
							$memo = $rst[$i]["memo"];
							$last_upd_user = $this->cmn->fn_nz($rst[$i]['user_name']);
						} else{
//2025-02-28追加
							$comment_no = $rst[$i]["comment_no"];
						}

						$read_name = "既";
						if ($rst[$i]["is_read"] == 0) {
							$read_name = "未";
						}
						$memo = mb_substr(rtrim(strip_tags($rst[$i]["memo"])),0,30);
						$str_ymd = date("y/m/d H:i",strtotime($rst[$i]["last_update"]));
						$edit_update_at = $this->cmn->fn_edit_short_at($rst[$i]["last_update"]);

				//リンクコピー用URL
						$link_url = $this->router->generate('app_global_link'
													,['kind' => 'm', 'key_no' => $rst[$i]["msg_no"] ]);

						$msg_title = mb_substr(rtrim($this->cmn->fn_nz($rst[$i]["msg_title"])),0,30);
						$atesaki = $rst[$i]["atesaki"];

						if ($filter_mode == "top"){
							$is_read = $rst[$i]["is_read"];
						} else {
							if ($rst[$i]["comment_no"] == null) {
								$is_read = $rst[$i]["is_read"];
							} else {
								$is_read = $rst[$i]["comment_is_read"];
							}
						}

						$msg_no = $rst[$i]["msg_no"];
						$path = $this->router->generate('app_msg_detail',['mode' => "view" ,'msg_no' => $msg_no ]);
//2025-08-16追加
						$comment_path = "";
						$comment_no = "";
						if (!is_null($rst[$i]["comment_no"])){
							$comment_no = $rst[$i]["comment_no"];
							$comment_path = $this->router->generate('app_comment_detail',[
																	 'msg_no'     => $msg_no
																	,'comment_no' => $comment_no
																	 ]);
						}
						$col = [ "path"				=> $path
								,"msg_no"			=> $msg_no
								,"read_name"		=> $read_name
								,"kokyaku_name"    	=> $this->cmn->fn_nz($rst[$i]["kokyaku_name"])
								,"msg_title"    	=> $msg_title
								,"memo" 			=> $memo
								,"last_upd_user"  	=> $rst[$i]["last_upd_user"]
								,"last_update"    	=> $edit_update_at
								,"category_name"    => $category_name
								,"thema_name" 		=> $thema_name
								,"state_name"       => $state_name
								,"sort_at"			=> $str_ymd
								,"link_url"			=> $link_url
//2025-02-24追加
								,"atesaki"	        => $atesaki
//2025-02-28追加
								,"comment_no"	    => $rst[$i]["comment_no"]
								,"is_read"	        => $is_read
//2025-08-16追加
								,"comment_path"		=> $comment_path
							   ];
					} else {
/*
						if ($filter_mode == "top"){
							$is_read = $rst[$i]["is_read"];
						} else {
							$is_read = "1";
							if ($rst[$i]["comment_modified_user_id"] != $login_user_id){
								if (($rst[$i]['comment_is_read'] == 0)){
									$read_name = "未";
									$is_read = "0";
								}
							}
						}
*/
						if ($rst[$i]["comment_no"] == null) {
							$is_read = $rst[$i]["is_read"];
						} else {
							$is_read = $rst[$i]["comment_is_read"];
						}
						$col = [ "msg_no"			=> $rst[$i]["msg_no"]
								,"comment_no"	    => $rst[$i]["comment_no"]
								,"action_id"	    => $rst[$i]["action_id"]
								,"is_read"	        => $is_read
								,"atesaki"	        => $rst[$i]["atesaki"]
								,"action_f"		    => $rst[$i]["action_f"]
							   ];
					}
					$data[] = [ "col" => $col ];
				}
			}
		}

		//DataTables para の取得
		$dt_para = $this->filter_s->get_datatables_para(self::cond_id);

		return [ "draw"            => $draw,
				 "recordsTotal"    => $record_count,
				 "recordsFiltered" => $record_count,
				 "data"            => $data,
				 "dt_para"         => $dt_para,
				 ];
	}

//2025-04-09変更
//メッセージ一覧の[既読更新]の対応 ($batch = 0:通常 1:既読更新)
	//2025-07-02追加 $download = 0:通常 1:ダウンロード時
	public function fn_msg_msg_list($para,$login_user_id,$batch=0,$download=0){

		$frm = $para["frm"];
		if ($batch == 1) {
			$length = null;
			$start = null;
			$order = null;
			$draw = null;
		} else {
			$length = $para["length"];
			$start = $para["start"];
			$order = $para["order"];
			$draw = $para["draw"];
		}

		$del_limit_dt = date("Y/m/d",strtotime("-1 month"));
		$read_limit_dt = date("Y/m/d",strtotime("-1 month"));
//2025-04-20変更(テーブル名にユニークIDを付加)
		$temp_user_atesaki = "##temp_user_atesaki" . uniqid();
		$sql  = "IF EXISTS (select name from tempdb..sysobjects";
		$sql .= " WHERE id = OBJECT_ID('tempdb..".$temp_user_atesaki."') and type = 'U')";
		$sql .= " drop table [{$temp_user_atesaki}];";
		$sql .= "  select row_number() over(partition by f.[msg_no] order by f.[msg_no],";
		$sql .= "	   isnull(f2.[user_role],isnull(f3.[user_role],-1)) desc) As [RowNo],";
		$sql .= "      [user_role] = isnull(convert(varchar(1),f2.[user_role]),isnull(convert(varchar(1),f3.[user_role]),'')),";
		$sql .= "      f.[msg_no],";
		$sql .= "[atesaki_name] = (case when f2.[user_type] = 2 then 'あなた' ";
		$sql .= "       when f3.[user_id] = '10' then '全員' ";
		$sql .= "       else f5.[soshiki_group_name] end),";
		$sql .= "[atesaki_fill] = (case when f2.[user_type] = 2 then 2 ";
		$sql .= "       when f3.[user_id] = '10' then 3 ";
		$sql .= "       else 4 end)";
		$sql .= "      into [{$temp_user_atesaki}]";
		$sql .= "      from [t_msg] f";
		$sql .= "	    left join [t_msg_user] f2";
		$sql .= "		  on  f2.msg_no = f.msg_no";
		$sql .= "		  and f2.comment_no is null";
		$sql .= "		  and f2.user_id = :login_user_id1";
		$sql .= "		  and f2.user_type = 2";
		$sql .= "		  and f2.user_role >= 0";
		$sql .= "		left join [t_msg_user] f3";
		$sql .= "		  on  f3.msg_no = f.msg_no";
		$sql .= "		  and f3.comment_no is null";
		$sql .= "		  and f3.user_type = 1";
		$sql .= "		  and f3.user_role >= 0";
		$sql .= "		left join [m_soshiki_user] f4";
		$sql .= "		  on  f4.soshiki_user_id = :login_user_id2";
		$sql .= "		  and f4.soshiki_group_id = f3.user_id";
		$sql .= "      left join [m_soshiki_group] f5";
		$sql .= "        on  f5.[soshiki_group_id] = f4.soshiki_group_id ";
		$sql .= "	  where (f2.msg_no is not null";
		$sql .= "	     or (f3.msg_no is not null and f4.soshiki_group_id = f3.user_id));";
		$paramVal = [];
		$paramVal += ["login_user_id1" => $login_user_id ];
		$paramVal += ["login_user_id2" => $login_user_id ];
		$ret = $this->dbs->executeUpdate($sql,$paramVal);

//2025-08-07追加
// ここで条件を付加してテンポラリーテーブルに抽出して ROW_NUMBER処理を行う
		$temp_msg_comment = "##temp_msg_comment" . uniqid();
		$sql  = "IF EXISTS (select name from tempdb..sysobjects";
		$sql .= " WHERE id = OBJECT_ID('tempdb..".$temp_msg_comment."') and type = 'U')";
		$sql .= " drop table [{$temp_msg_comment}];";
		$sql .= "     select";
		$sql .= "      s.msg_no";
		$sql .= "     ,[comment_no] = s.comment_no";
		$sql .= "     ,[from_user_id] = s.from_user_id";
		$sql .= "     ,[comment_memo] = s.s_memo";
		$sql .= "     ,[comment_modified_user_name] = s2.[社員名]";
		$sql .= "     ,[comment_modified_user_id] = isnull(s.modified_user_id,s.from_user_id)";
		$sql .= "     ,[comment_modified_at] = isnull(s.modified_at,s.from_at)";
		$sql .= "     ,[comment_is_read] = isnull(ac.is_read,0)";
		$sql .= "      into [{$temp_msg_comment}]";
		$sql .= "     from t_msg_comment s";
		$sql .= "        left join [M_User] s1";
		$sql .= "          on s1.[ユーザーID] = isnull(s.[modified_user_id],s.[from_user_id])";
		$sql .= "        left join [C_社員] s2";
		$sql .= "          on s2.[社員CD] = s1.[社員CD]";
		$sql .= "	     left join [t_msg_action] ac";
		$sql .= "		   on ac.msg_no = s.msg_no";
		$sql .= "		   and ac.comment_no = s.comment_no";
		$sql .= "		   and ac.user_id = :ac_user_id";
		$sql .= "     where isnull(s.is_delete,0) = :is_comment_delete";
		$paramVal = [];
		$paramVal += ["ac_user_id"        => $login_user_id ];
		$paramVal += ["is_comment_delete" => 0 ];	//0:通常データ

//投稿日の条件を追加
		// 投稿日(開始)
		if (!empty($frm['txt_tokobi_from'])) {
			$sql .= "   and isnull(convert(char,isnull(s.[modified_at],s.[from_at]),111),0) >= :tokobi_from";
			$paramVal += ["tokobi_from" => $frm['txt_tokobi_from'] ];
		}
		// 投稿日(終了)
		if (!empty($frm['txt_tokobi_to'])) {
			$sql .= "   and isnull(convert(char,isnull(s.[modified_at],s.[from_at]),111),0) <= :tokobi_to";
			$paramVal += ["tokobi_to" => $frm['txt_tokobi_to'] ];
		}

/**/
//コメントの検索がある場合は、メイン抽出にコメントのテンポラリーを条件付加する必要がある
		if (!empty($frm['txt_filter'])) {
			$filter_str = $frm['txt_filter'];
			$filter_str = preg_replace('/　/', ' ', $filter_str);	// 全角スペースを半角スペースへ
			$filter_str = preg_replace('/\s+/', ' ', $filter_str);	// 連続する半角スペースを1つの半角スペースへ
//2025-04-08追加
			$filter_str = trim($filter_str);
			// 半角スペースで配列変換
			$filter_array = explode(' ', $filter_str);

			$filter["filter"] = $filter_str;

			// 選択された検索項目
			if (!empty($frm['txt_filter_item'])) {
				$sql2 = [];
				$sql_def = " isnull(@1,'') like @2";
//				$sql_def = " isnull(@1,'') like CONVERT(NVARCHAR(32), @2)";
				for ($i = 0;$i < count($frm['txt_filter_item']);$i++) {
					$fld = "";
					$fld2 = "";
					$pre = "s.";
					switch ($frm['txt_filter_item'][$i]) {
						case "コメント":
							$fld = "s_memo";
							break;
						case "差出人":
							$fld = "from_by";
							break;
						case "宛先":
							$fld = "atena";
							break;
						case "添付":
							$fld = "attached_file";
							break;
					}
					if ($fld != "") {
						for ($j = 0;$j < count($filter_array);$j++) {
							$val = $filter_array[$j];

							$para = ":{$fld}{$i}{$j}";
							$sql_d = str_replace('@1', $pre.$fld, $sql_def);

							if ($fld2 != "") {
								$para = ":{$fld2}{$i}{$j}";
							}
							$sql_d = str_replace('@2', $para, $sql_d);

							$para2 = "{$fld}{$i}{$j}";
							if ($fld2 != "") {
								$para2 = "{$fld2}{$i}{$j}";
							}

							switch ($frm['txt_filter_item'][$i]) {
								case "コメント":
									$sql2[] = $sql_d;
									//$comment_paramVal += [ $para2 => "%{$val}%" ];
									$paramVal += [ $para2 => "%{$val}%" ];
									//$sql2[] = "comment-sql";	//後でSQLを置き換える
									break;
								case "宛先":
									$atena_sql[] = $sql_d;
									$paramVal += [ $para2 => "%{$val}%" ];
									$sql2[] = "atena-sql";	//後でSQLを置き換える
									break;
								default:
									$sql2[] = $sql_d;
									$paramVal += [ $para2 => "%{$val}%" ];
									break;
							}
						}
					}
				}

				if ($sql2 != "") {

					if (count($sql2) > 0) {
						$sql2 = "( " . implode(" or ", $sql2) . ")";
						$sql .= " and " . $sql2;
					}
				}
			}
		}
/**/

		$ret = $this->dtctl->fn_comment_filter_get("msg_list",$frm);
		if ($ret["status"] == true) {
			if ($ret["criteria"] != "") {
				$sql .= $ret["criteria"];
			}

			if ($ret["paramsVal"] != []) {
				$paramVal += $ret["paramsVal"];
			}
		}
		$ret = $this->dbs->executeUpdate($sql,$paramVal);


//2025-04-20変更(テーブル名にユニークIDを付加)
		$temp_new_comment = "##temp_new_comment" . uniqid();
		$sql  = "IF EXISTS (select name from tempdb..sysobjects";
		$sql .= " WHERE id = OBJECT_ID('tempdb..".$temp_new_comment."') and type = 'U')";
		$sql .= " drop table [{$temp_new_comment}];";
		$sql .= "     select";
		$sql .= "       ROW_NUMBER() OVER(PARTITION BY [msg_no] ORDER BY";
		$sql .= "         [comment_modified_at] desc) AS RowNo";
		$sql .= "     ,[msg_no]";
		$sql .= "     ,[comment_no]";
		$sql .= "     ,[from_user_id]";
		$sql .= "     ,[comment_memo]";
		$sql .= "     ,[comment_modified_user_name]";
		$sql .= "     ,[comment_modified_user_id]";
		$sql .= "     ,[comment_modified_at]";
		$sql .= "     ,[comment_is_read]";
		$sql .= "      into [{$temp_new_comment}]";
		$sql .= "     from [{$temp_msg_comment}]";
		$ret = $this->dbs->executeUpdate($sql);

		if ($batch == 1) {
			$sql  = "select a.msg_no";
			$sql .= ",z.comment_no";
			$sql .= ",[action_id] = b.id";
			$sql .= ",[is_read] = isnull(b.is_read,1)";
			$sql .= ",[action_f] = (case when b.[user_id] is null then 0 else 1 end)";
			$sql .= ",z.[comment_modified_user_id]";
			$sql .= ",[comment_is_read] = isnull(z.[comment_is_read],1)";
			$sql .= ",[atesaki] = f.[atesaki_name]";
		} else {
//2025-04-12変更
			$sql  = "select";
			$sql .= " [is_read] = (case when (isnull(z.[comment_modified_at],'') <> '' ";
			$sql .= " and isnull(a.[modified_at],a.[from_at]) < isnull(z.[comment_modified_at],''))";
			$sql .= " then (case when z.[comment_modified_user_id] <> :update_user_id1";
			$sql .= "   then (case when z.[comment_is_read] = 0 then 0 else 1 end) else 1 end)";
			$sql .= " else (case when isnull(a.[modified_user_id],a.[from_user_id]) <> :update_user_id2";
			$sql .= "   then (case when isnull(b.is_read,0) = 0 then 0 else 1 end) else 1 end) end)";

			$sql .= ",[atesaki] = f.[atesaki_name]";
			$sql .= ",[kokyaku_name] = (case when isnull(a.kokyaku_cd,'') = '' then '－' else trim(k.[組合名] + ' ' + isnull(k.[支部名],'')) end)";

			$sql .= ",[msg_title] = (case when isnull(a.is_delete,0) = 1 then '【削除】' ";
			$sql .= " when isnull(a.is_draft,0) = 1 then '【下書き保存中】' else '' end) + a.[msg_title]";

			$sql .= ",[memo] = (case when (isnull(z.[comment_modified_at],'') <> '' ";
			$sql .= " and isnull(a.[modified_at],a.[from_at]) < isnull(z.[comment_modified_at],''))";
			$sql .= " then z.[comment_memo] else a.[s_memo] end)";

			$sql .= ",[last_upd_user] = (case when (isnull(z.[comment_modified_at],'') <> '' ";
			$sql .= " and isnull(a.[modified_at],a.[from_at]) < isnull(z.[comment_modified_at],''))";
			$sql .= " then z.[comment_modified_user_name] else c1.[社員名] end)";

			$sql .= ",[last_update] = (case when (isnull(z.[comment_modified_at],'') <> '' ";
			$sql .= " and isnull(a.[modified_at],a.[from_at]) < isnull(z.[comment_modified_at],''))";
			$sql .= " then z.[comment_modified_at] else isnull(a.[modified_at],a.[from_at]) end)";

			$sql .= ",a.category_id";
			$sql .= ",a.thema_id";
			$sql .= ",a.state_id";
			$sql .= ",a.msg_no";
			$sql .= ",a.is_delete";
			$sql .= ",a.is_draft";
			$sql .= ",update_user_id = isnull(a.[modified_user_id],a.[from_user_id])";
			$sql .= ",b.is_pin";
			$sql .= ",z.[comment_memo]";
			$sql .= ",z.[comment_modified_user_name]";
			$sql .= ",z.[comment_modified_at]";
			$sql .= ",z.[comment_modified_user_id]";
//2025-03-06変更
			$sql .= ",[comment_is_read] = isnull(z.[comment_is_read],0)";
//2025-02-24追加
			$sql .= ",[comment_no] = isnull(z.[comment_no],0)";
//2025-07-05追加
			$sql .= ",[shibu_name] = isnull(k.[支部名],'')";
		}
		$sql2  = " from [t_msg] a";
		$sql2 .= "    left join [t_msg_action] b";
		$sql2 .= "      on  b.user_id = :login_user_id1";
		$sql2 .= "      and b.msg_no = a.msg_no";
		$sql2 .= "      and b.comment_no is null";
		$sql2 .= "    left join [M_User] c";
		$sql2 .= "       on c.[ユーザーID] = isnull(a.[modified_user_id],a.[from_user_id])";
		$sql2 .= "    left join [C_社員] c1";
		$sql2 .= "       on c1.[社員CD] = c.[社員CD]";
		$sql2 .= "    left join [M_顧客] k";
		$sql2 .= "       on k.[顧客CD] = a.[kokyaku_cd]";

//2025-04-12追加
		$sql2 .= " left join [{$temp_user_atesaki}] f";
		$sql2 .= "      on  f.msg_no = a.msg_no";
		$sql2 .= "      and f.RowNo = 1";
		if ($frm["atesaki_fill"] <> 1) {
			$sql2 .= "  and (f.[atesaki_fill] = :atesaki_fill)";
		}

		//更新日時の最大値をもつコメントデータの取得
		$sql2 .= "  left join [{$temp_new_comment }] z";
		$sql2 .= "     on  z.msg_no = a.msg_no";
		$sql2 .= "     and z.RowNo = 1";
		$sql2 .= " where a.id is not null";
 		$sql2 .= "  and ((isnull(a.[modified_user_id],a.[from_user_id]) = :login_user_id2)";
		$sql2 .= "  and  ((isnull(a.is_delete,0)  = :is_delete1)";
		$sql2 .= "  or    (isnull(a.is_draft,0)    = :is_draft1";
		$sql2 .= "   and  (convert(char,a.from_at,111) >= :del_limit_dt)))";

		$sql2 .= "  or   ((isnull(a.is_delete,0)  = :is_delete)";
		$sql2 .= "  and   (isnull(a.is_draft,0)   = :is_draft)";
		$sql2 .= "  and   (isnull(a.is_reserve,0) = :is_reserve)))";

//2025-04-12追加
		$sql2 .= "  and  (f.atesaki_fill is not null)";

		$paramVal = [];
		$paramVal += ["login_user_id1"    => $login_user_id ];
		$paramVal += ["login_user_id2"    => $login_user_id ];
		$paramVal += ["ac_user_id"        => $login_user_id ];
		$paramVal += ["is_delete"         => 0 ];	//0:投稿データ 1:削除データ
		$paramVal += ["is_draft"          => 0 ];	//0:投稿データ 1:下書きデータ
		$paramVal += ["is_reserve"        => 0 ];	//0:投稿データ 1:投稿予約データ
		$paramVal += ["is_delete1"        => 1 ];	//1:削除データ
		$paramVal += ["is_draft1"         => 1 ];	//1:下書きデータ
//2025-04-13追加
//		$paramVal += ["is_reaction_allow" => 1 ];	//1:リアクションを全員に許可
		$paramVal += ["del_limit_dt"      => $del_limit_dt ];	//1か月以前
//2025-04-12追加
//		if ($frm["atesaki_fill"] <> 1) {
			$paramVal += ["atesaki_fill"  => $frm["atesaki_fill"] ];
//			$paramVal += ["atesaki_fill2" => $frm["atesaki_fill"] ];
//		}
		$paramVal += ["update_user_id1"   => $login_user_id ];
		$paramVal += ["update_user_id2"   => $login_user_id ];

//2025-04-15追加
//		$frm["dt_length"] = $para["length"];
//		$frm["dt_start"] = $para["start"];
//		$frm["dt_order"] = $para["order"];
//		$frm["dt_draw"] = $para["draw"];

		// 抽出条件の取得
		$ret_w = $this->createWhere($frm);

//2025-08-07追加
		$ret_w["sql"] = str_replace('[temp_msg_comment]', '['.$temp_msg_comment.']', $ret_w["sql"]);

		$sql2 .= $ret_w["sql"];
		$paramVal += $ret_w["paramVal"];

		//全体の件数取得用
		$sql_count = "select a.id ".$sql2;

		//from句以下の結合
		$sql .= $sql2;

		//DataTables用の並び順を結合
		$dt_order = $this->filter_s->get_datatables_order($order);
		//並び替え指定が"なし"の場合は、初期値をセット
		$dt_order = ($dt_order == "" ? " order by 7 desc" : $dt_order);

		$sql .= $dt_order;

//2025-07-02 $download == 0 追加
		if ($batch == 0 && $download == 0) {
			//Offsetの設定(１ページ分のみ取得)
			$sql .= " OFFSET {$start} ROWS";
			$sql .= " FETCH NEXT {$length} ROWS ONLY";
		}

		return [$sql_count,$sql,$paramVal];

	}

	/**
	*  抽出条件 作成処理
	*  $frm  object :フォーム入力項目の連想配列
	*
	*/
	private function createWhere($frm) {

		$filter_mode = $frm['filter_mode'];
		$sql = "";
		$paramVal = [];

		$filter = [ "tokobi_from"	=> ""	// 投稿日(開始)
				   ,"tokobi_to"		=> ""	// 投稿日(終了)
				   ,"filter_item1"	=> ""	// 抽出条件1 (顧客あり,案件あり,研修あり)
				   ,"category"		=> ""	// カテゴリ―
				   ,"thema"			=> ""	// テーマ
				   ,"state"			=> ""	// 状態
				   ,"filter"		=> ""	// 検索文字列
				   ,"filter_item2"	=> ""	// 抽出条件1 (顧客名,タイトル,本文,コメント,差出人,宛先,添付)
				   ,"folder_id"		=> ""	// フォルダID
				  ];

		// メッセージ一覧
		$folder_id = $frm["txt_target_folder"];
//2024-12-06変更
//		if ($folder_id != 1){
		if (($folder_id != 1) && ($folder_id != "")){
			$sql .= "   and folder_id = :folder_id";
			$paramVal += ["folder_id" => $frm['txt_target_folder'] ];

//2024-11-10追加
			$this->requestStack->getSession()->set('gw_msg/folder_id',$frm['txt_target_folder']);
		}
		$filter["folder_id"] = $folder_id;

		// 投稿日(開始)
		if (!empty($frm['txt_tokobi_from'])) {
//2024-11-30変更(コメント更新日最大>メッセージ更新日>投稿日)
//			$sql .= "   and isnull(convert(char,a.[from_at],111),0) >= :tokobi_from";
			$sql .= "   and isnull(convert(char,isnull(z.[comment_modified_at],isnull(a.[modified_at],a.[from_at])),111),0) >= :tokobi_from";
			$paramVal += ["tokobi_from" => $frm['txt_tokobi_from'] ];

			$filter["tokobi_from"] = $frm['txt_tokobi_from'];
		}
		// 投稿日(終了)
		if (!empty($frm['txt_tokobi_to'])) {
//2024-11-30変更(コメント更新日最大>メッセージ更新日>投稿日)
//			$sql .= "   and isnull(convert(char,a.[from_at],111),0) <= :tokobi_to";
			$sql .= "   and isnull(convert(char,isnull(z.[comment_modified_at],isnull(a.[modified_at],a.[from_at])),111),0) <= :tokobi_to";
			$paramVal += ["tokobi_to" => $frm['txt_tokobi_to'] ];

			$filter["tokobi_to"] = $frm['txt_tokobi_to'];
		}

		$filter_item1  = [0,0,0];
		//顧客あり
		if (!empty($frm['chk_kokyaku_ari'])){
			$sql .= "   and isnull(a.[kokyaku_cd],0) <> :kokyaku_cd";
			$paramVal += ["kokyaku_cd" => 0 ];

			$filter_item1[0] = 1;
		}
		//案件あり
		if (!empty($frm['chk_anken_ari'])){
			$sql .= "   and isnull(a.[anken_no],0) <> :anken_no";
			$paramVal += ["anken_no" => 0 ];

			$filter_item1[1] = 1;
		}
		//研修あり
/*作成中
		if (!empty($frm['chk_kenshu_ari'])){
			dump("chk_kenshu_ari");

			$filter_item1[2] = 1;
		}
*/
		$filter["filter_item1"] = $filter_item1;

		//カテゴリ―,テーマ,状態の SQL文字列
		$sql_sub = "";

		//カテゴリ
		$sql_where = "";
		$filter["category"] = "";
		if (!empty($frm['cbo_category']) && ($frm['cbo_category'][0] != "")){
			$criteria_in = "";
			for ($i = 0;$i < count($frm['cbo_category']);$i++) {
				$paramVal += ["category{$i}" => $frm['cbo_category'][$i] ];
				$sql_where .= ($sql_where == "") ? "" : " , ";
				$sql_where .= ":category{$i}";
				$criteria_in .= ($criteria_in == "") ? "" : ",";
				$criteria_in .= $frm['cbo_category'][$i];
			}
			$filter["category"] = $criteria_in;

			$sql_sub .= ($sql_sub == "") ? "" : " or ";
			$sql_sub .= " a.msg_no in(select a.msg_no from ufn_SplitString([category_id]) where value in ({$sql_where})) ";
		}
		//テーマ
		$sql_where = "";
		if (!empty($frm['cbo_thema']) && ($frm['cbo_thema'][0] != "")){
			$criteria_in = "";
			for ($i = 0;$i < count($frm['cbo_thema']);$i++) {
				$paramVal += ["thema{$i}" => $frm['cbo_thema'][$i] ];
				$sql_where .= ($sql_where == "") ? "" : " , ";
				$sql_where .= ":thema{$i}";

				$criteria_in .= ($criteria_in == "") ? "" : ",";
				$criteria_in .= $frm['cbo_thema'][$i];
			}
			$filter["thema"] = $criteria_in;

			$sql_sub .= ($sql_sub == "") ? "" : " and ";
			$sql_sub .= " a.msg_no in(select a.msg_no from ufn_SplitString([thema_id]) where value in ({$sql_where})) ";
		}
		//状態
		$sql_where = "";
		if (!empty($frm['cbo_state']) && ($frm['cbo_state'][0] != "")){
			$criteria_in = "";
			for ($i = 0;$i < count($frm['cbo_state']);$i++) {
				$paramVal += ["state{$i}" => $frm['cbo_state'][$i] ];
				$sql_where .= ($sql_where == "") ? "" : " , ";
				$sql_where .= ":state{$i}";

				$criteria_in .= ($criteria_in == "") ? "" : ",";
				$criteria_in .= $frm['cbo_state'][$i];
			}
			$filter["state"] = $criteria_in;

			$sql_sub .= ($sql_sub == "") ? "" : " and ";
			$sql_sub .= " a.msg_no in(select a.msg_no from ufn_SplitString([state_id]) where value in ({$sql_where})) ";
		}

		if ($sql_sub != ""){
			$sql .= " and ({$sql_sub})";
		}
		// 検索文字
		$comment_naiyo_f = 0;
		$comment_paramVal = [];
		$comment_sql = [];
		$atena_sql = [];

//2025-04-20変更
		$filter_item2  = [0,0,0,0,0,0,0];
		for ($i = 0;$i < count($frm['txt_filter_item']);$i++) {
			switch ($frm['txt_filter_item'][$i]) {
				case "顧客名":
					$filter_item2[0] = 1;
					break;
				case "タイトル":
					$filter_item2[1] = 1;
					break;
				case "本文":
					$filter_item2[2] = 1;
					break;
				case "コメント":
					$filter_item2[3] = 1;
					break;
				case "差出人":
					$filter_item2[4] = 1;
					break;
				case "宛先":
					$filter_item2[5] = 1;
					break;
				case "添付":
					$filter_item2[6] = 1;
					break;
			}
		}

		if (!empty($frm['txt_filter'])) {
			$filter_str = $frm['txt_filter'];
			$filter_str = preg_replace('/　/', ' ', $filter_str);	// 全角スペースを半角スペースへ
			$filter_str = preg_replace('/\s+/', ' ', $filter_str);	// 連続する半角スペースを1つの半角スペースへ
//2025-04-08追加
			$filter_str = trim($filter_str);
			// 半角スペースで配列変換
			$filter_array = explode(' ', $filter_str);

			$filter["filter"] = $filter_str;

			// 選択された検索項目
			if (!empty($frm['txt_filter_item'])) {
				$sql2 = [];
				$sql_def = " isnull(@1,'') like @2";
//				$sql_def = " isnull(@1,'') like CONVERT(NVARCHAR(32), @2)";
				for ($i = 0;$i < count($frm['txt_filter_item']);$i++) {
					$fld = "";
					$fld2 = "";
					$pre = "a.";
					switch ($frm['txt_filter_item'][$i]) {
						case "顧客名":
							$pre = "k.";
							$fld = "組合名";
							$fld2 = "kumiai_name";
							break;
						case "タイトル":
							$fld = "msg_title";
							break;
						case "本文":
							$fld = "s_memo";
							break;
						case "コメント":
							$pre = "s.";
							$fld = "comment_memo";
							$comment_naiyo_f = 1;
							break;
						case "差出人":
							$fld = "from_by";
							break;
						case "宛先":
							$pre = "w.";
							$fld = "atena";
							break;
						case "添付":
							$fld = "attached_file";
							break;
					}
					if ($fld != "") {
						for ($j = 0;$j < count($filter_array);$j++) {
							$val = $filter_array[$j];

							$para = ":{$fld}{$i}{$j}";
							$sql_d = str_replace('@1', $pre.$fld, $sql_def);

							if ($fld2 != "") {
								$para = ":{$fld2}{$i}{$j}";
							}
							$sql_d = str_replace('@2', $para, $sql_d);

							$para2 = "{$fld}{$i}{$j}";
							if ($fld2 != "") {
								$para2 = "{$fld2}{$i}{$j}";
							}

							switch ($frm['txt_filter_item'][$i]) {
								case "コメント":
									$comment_sql[] = $sql_d;
									$comment_paramVal += [ $para2 => "%{$val}%" ];
									$paramVal += [ $para2 => "%{$val}%" ];
									$sql2[] = "comment-sql";	//後でSQLを置き換える
									break;
								case "宛先":
									$atena_sql[] = $sql_d;
									$paramVal += [ $para2 => "%{$val}%" ];
									$sql2[] = "atena-sql";	//後でSQLを置き換える
									break;
								default:
									$sql2[] = $sql_d;
									$paramVal += [ $para2 => "%{$val}%" ];
									break;
							}

						}
					}
				}

				if ($filter_item2 != []) {

					if (count($sql2) > 0) {
						$sql2 = "( " . implode(" or ", $sql2) . ")";
						$sql .= " and " . $sql2;
					}
				}
			}
		}
		$filter["filter_item2"] = $filter_item2;

		// 宛名検索
		if (count($atena_sql) > 0) {
			$atena = "( " . implode(" or ", $atena_sql) . ")";
			$atena = str_replace("w.atena", "isnull(w13.社員名,w22.社員名)", $atena);
			$sql_atena  = " ((select count(w.id)";
			$sql_atena .= "   from t_msg w";
			//グループ検索
			$sql_atena .= "      left join t_msg_user w1";
			$sql_atena .= "         on w1.msg_no = w.msg_no";
			$sql_atena .= "         and w1.user_type = :user_type1";

			$sql_atena .= "         and w1.user_role >= :user_role1";

//2024-12-27変更
/*
			$sql_atena .= "      left join m_user_soshiki w11";
			$sql_atena .= "         on w11.soshiki_group_id = w1.user_id";
			$sql_atena .= "         and w11.user_id = :atena_user_id";
*/
			$sql_atena .= "      left join m_soshiki_user w11";
			$sql_atena .= "         on w11.soshiki_group_id = w1.user_id";
			$sql_atena .= "         and w11.soshiki_user_id = :atena_user_id";

			$sql_atena .= "      left join M_User w12";
			$sql_atena .= "         on w12.[ユーザーID] = w11.soshiki_user_id";
			$sql_atena .= "      left join C_社員 w13";
			$sql_atena .= "         on w13.[社員CD] = w12.[社員CD]";
			//ユーザー検索
			$sql_atena .= "      left join t_msg_user w2";
			$sql_atena .= "         on w2.msg_no = w.msg_no";
			$sql_atena .= "         and w2.user_type = :user_type2";

			$sql_atena .= "         and w2.user_role >= :user_role2";

			$sql_atena .= "      left join M_User w21";
			$sql_atena .= "         on w21.[ユーザーID] = w2.user_id";
			$sql_atena .= "      left join C_社員 w22";
			$sql_atena .= "         on w22.[社員CD] = w21.[社員CD]";
			$sql_atena .= "   where {$atena} and w.msg_no = a.msg_no) > 0)";
			$sql = str_replace("atena-sql",$sql_atena,$sql);
			$paramVal += ["atena_user_id" => $this->user->getUserId() ];
			$paramVal += ["user_type1"     => 1 ];	//1:グループ
			$paramVal += ["user_type2"     => 2 ];	//2;ユーザー
			if ($filter_mode == "top"){
				$paramVal += [ "user_role1" => 1 ];		//メンバー以上
				$paramVal += [ "user_role2" => 1 ];		//メンバー以上
			} else{
				$paramVal += [ "user_role1" => 0 ];		//閲覧以上
				$paramVal += [ "user_role2" => 0 ];		//閲覧以上
			}
		}

		// コメント検索
		if (count($comment_sql) > 0) {
			$comment = "( " . implode(" or ", $comment_sql) . ")";
			//$comment = str_replace("x.naiyo", "ifnull(w2.group_name,w3.user_name)", $comment);
			$sql_comment  = " ((select count(s.msg_no)";
//			$sql_comment .= "   from t_msg_comment s";
			$sql_comment .= "   from [temp_msg_comment] s";
			$sql_comment .= "   where {$comment} and s.msg_no = a.msg_no) > 0)";
			$sql = str_replace("comment-sql",$sql_comment,$sql);
		}

		$comment_naiyo = "";
		// 削除データは除く
		$comment_naiyo .= "ifnull(s2.is_delete,0) = :is_comment_delete2";
		$comment_paramVal += [":is_comment_delete2"  => 0 ];
		if (count($comment_sql) > 0) {
//			$comment_naiyo .= " and ";
			$comment_naiyo = " ( " . implode(" or ", $comment_sql) . ")";
		}

//2025-06-30追加
		$ret = $this->dtctl->fn_dtctl_filter_get("msg_list",$frm);
		if ($ret["status"] == true) {
			if ($ret["criteria"] != "") {
				$sql .= $ret["criteria"];
			}

			if ($ret["paramsVal"] != []) {
				$paramVal += $ret["paramsVal"];
			}
		}

//上段抽出条件の保存
		//初期値データの取得
		$data_def = $this->fn_ex_condition_default_get();

//2025-07-19追加
		$dtctl_para = $this->dtctl->fn_dtctl_para_get($frm);

		$cond_para  = ['tokobi_from'     => $filter['tokobi_from'] ];
		$cond_para += ['tokobi_to'       => $filter['tokobi_to'] ];
		$cond_para += ['filter_item1'    => $filter['filter_item1'] ];
		$cond_para += ['category'        => $filter['category'] ];
		$cond_para += ['thema'           => $filter['thema'] ];
		$cond_para += ['state'           => $filter['state'] ];
		$cond_para += ['filter'          => $filter['filter'] ];
		$cond_para += ['filter_item2'    => $filter['filter_item2'] ];
		$cond_para += ['folder_id'       => $filter['folder_id'] ];
		$cond_para += ['tokobi_from_def' => $data_def["tokobi_from"] ];
		$cond_para += ['tokobi_to_def'   => $data_def["tokobi_to"] ];
		$cond_para += ['filter_item1_def'=> $data_def["filter_item1"] ];
		$cond_para += ['filter_item2_def'=> $data_def["filter_item2"] ];
//2024-11-07追加
		$cond_para += ['search_mode'     => $frm["search_mode"] ];
		$cond_para += ['search_mode_def' => $data_def["search_mode"] ];

//2025-04-15追加
//		$cond_para += ['dt_length'       => $frm["dt_length"] ];
//		$cond_para += ['dt_start'        => $frm["dt_start"] ];
//		$cond_para += ['dt_order'        => $frm["dt_order"] ];
//		$cond_para += ['dt_draw'         => $frm["dt_draw"] ];
//2025-04-20追加(検索)
		$cond_para += ['atesaki_fill'    => $frm["atesaki_fill"] ];
//2025-07-19追加
		$cond_para += ['dtctl'           => $dtctl_para ];

		//抽出条件の保存
		$ret = $this->bm->setBbreadCrumbPara(self::cond_id,"cond",$cond_para);

		$ret = [ "sql"              => $sql
				,"paramVal"         => $paramVal
/* 2025-08-07削除
				,"comment_naiyo"    => $comment_naiyo
				,"comment_paramVal" => $comment_paramVal
				,"comment_naiyo_f"  => $comment_naiyo_f
*/
			  ];

		return $ret;

	}
	//スケジュールデータ抽出処理
	public function get_yotei_list_data($frm){
		$msg_no = 0;
		if (!empty($frm['txt_msg_no'])) {
			$msg_no = $frm['txt_msg_no'];
		}

		//スケジュールの取得
		$sql  = "select a.*";
		$sql .= " ,[yotei_shubetsu_name] = isnull(c.[short_name],left(c.[yotei_shubetsu_name],6))";
		$sql .= ",[kokyaku_name] = (case when a.kokyaku_cd is null then '－' ";
		$sql .= " else isnull(k.[組合名],'') + ' ' + isnull(k.[支部名],'') end)";
		$sql .= ",d.[msg_title]";
		$sql .= " from [t_yotei] a";
		$sql .= "    left join [m_yotei_shubetsu] c";
		$sql .= "       on c.[yotei_shubetsu_id] = a.[yotei_shubetsu_id]";
		$sql .= "    left join [t_msg] d";
		$sql .= "       on d.[msg_no] = a.[link_msg_no]";
		$sql .= "    left join [M_顧客] k";
		$sql .= "       on k.[顧客CD] = a.[kokyaku_cd]";
		$sql .= " where (a.[link_msg_no] = :msg_no)";
		$sql .= " order by a.[yoteibi_s] desc";

		$paramVal = ["msg_no" => $msg_no ];
		$row = $this->dbs->fetchAllAssociative($sql,$paramVal);

		$data = [];
		if ($row !== false){
			for ($i = 0;$i < count($row);$i++){
				$sche_no = $row[$i]["yotei_no"];
				//{% set ndata = sche[i].date~":"~u['user'].user_id~":"~u['user'].data_id %}
				$ndata = "-";

				$yotei_path = $this->router->generate('app_schedule_detail'
					,["mode" => "tview","sche_no" => $sche_no,"ndata" => $ndata,"caller" => "-"]);
				$msg_no = $row[$i]["link_msg_no"];
				$comment_path = $this->router->generate('app_msg_detail'
								,['mode' => "view" ,'msg_no' => $msg_no ]);

				$kokyaku_cd = $row[$i]["kokyaku_cd"];
				$kokyaku_path = "";
				if ($kokyaku_cd != "") {
					$kokyaku_path = $this->router->generate('app_kokyaku_detail'
						,['kokyaku_cd' => $kokyaku_cd,'caller'=>"-" ]);
				}
				$kokyaku_path = $this->router->generate('app_msg',["nav" => "-","caller" => "-"]);
				$col = ["yotei_naiyo"   => $row[$i]["yotei_naiyo"]
						,"yoteibi_s"     => $this->cmn->fn_nz($row[$i]["yoteibi_s"])
						,"start_ts"   => $this->cmn->fn_nz($row[$i]["start_ts"])
						,"yotei_shubetsu_name"    => $this->cmn->fn_nz($row[$i]["yotei_shubetsu_name"])
						,"comment"       => $row[$i]["msg_title"]
						,"kokyaku_name"  => $this->cmn->fn_nz($row[$i]["kokyaku_name"])
						,"yotei_path"    => $yotei_path
						,"comment_path"  => $comment_path
						,"kokyaku_path"  => $kokyaku_path
					   ];
				$data[] = [ "col" => $col ];
			}
		}

		$sagyo = [ 'kensu' => count($row), 'data' => $data ];
		return $sagyo;
	}

}
