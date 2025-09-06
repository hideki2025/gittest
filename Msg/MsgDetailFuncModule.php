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

class MsgDetailFuncModule{

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
								,private readonly LogModule $lgm
    							,private readonly Environment $twig){
		//$this->dbs = $connection;
		$this->dbs = $doctrine->getConnection('remote');

		//$this->my = $this->container->get('doctrine')->getConnection('local');
		$this->my = $doctrine->getConnection('local');
 		$this->user = $security->getUser();
		$this->logger = $logging;
    }


	//メッセージ情報の取得
	public function msg_detail_get($mode,$msg_no,$folder_id=0,$kokyaku_cd="0"){

		$page_id = "msg_detail";

		//ログインユーザーよりユーザー情報の取得
		$user_id = $this->user->getUserId();

		$table = "t_msg";
		$msg_role = "";
//2025-06-04追加(コメントで案件とリンクしているか判定フラグ)0:いない 1:いる
		$comment_anken_link_f = 0;

		//相互リンクの取得
		$link_msg_no = "";
		$link_comment_no = "";
		$link_msg_title = "";
		$link_comment_title = "";
		$link_yotei_naiyo = "";
		$link_yotei_no = "";
		$link_kind = "";
		$comment_return_path = "";
		$anken_no = 0;
		$msg_title = "";
		$memo = "";
		$kokyaku_name = "";
		$tab_no = "";
		if ($this->requestStack->getSession()->has('link_kind')){
			$link_kind = $this->requestStack->getSession()->get('link_kind');
			$this->requestStack->getSession()->remove('link_kind');
			
			if ($link_kind == "tab") {
				//タブリンクの取得
				if ($this->requestStack->getSession()->has('link_no')){
					$link_no = $this->requestStack->getSession()->get('link_no');
					if (str_contains($link_no,",")) {
						$array_link_no = explode (",",$link_no);
						if ($array_link_no[0] == "tab") {
							$tab_no = $array_link_no[1];
						}
					} else {
						$anken_no = $link_no;
					}

					$this->requestStack->getSession()->set('upd_link_no',$link_no);
					$this->requestStack->getSession()->remove('link_no');

//2025-06-06追加
					//案件タイトル取得
					if ($anken_no != 0){
						$ret = $this->plm->fn_anken_info_get($anken_no);
						if ($ret["status"] == true) {
							$anken_data = $ret["data"];
							$kokyaku_name = $anken_data["kokyaku_name"];
							$folder_id = $anken_data["folder_id"];
							$msg_title = $anken_data["msg_title"];
							$memo = $anken_data["memo"];
//2025-07-09追加
							$kokyaku_cd = $anken_data["kokyaku_cd"];
						}
					}
				}
			}

			if ($link_kind == "comment") {
				$cross_comment_no = $this->requestStack->getSession()->get('cross_comment_no');
				if (is_null($cross_comment_no) == false) {
					//文字列を","で分割して取得する
					$array_cross_no = explode (",",$cross_comment_no);
					$link_msg_no = $array_cross_no[0];
					$link_comment_no = $array_cross_no[1];
					if (count($array_cross_no) > 2) {
						$kokyaku_cd = $array_cross_no[2];
					}
					$this->requestStack->getSession()->remove('cross_comment_no');

					if ($link_msg_no != ""){
						$sql = "select msg_title from t_msg where msg_no = :msg_no";
						$paramVal = ["msg_no" => $link_msg_no ];
						$rst_msg = $this->dbs->fetchAssociative($sql,$paramVal);
						if ($rst_msg != false) {
							$link_msg_title = $rst_msg["msg_title"];
						} else {
							$link_msg_title = sprintf('%08d', $link_msg_no);
						}
					}
					if ($link_comment_no != "") {
						$link_comment_title = sprintf('%04d', $link_comment_no);
					}
				}
			}
			if ($link_kind == "yotei") {
				if ($this->requestStack->getSession()->has('cross_yotei_no')){
					$cross_yotei_no = $this->requestStack->getSession()->get('cross_yotei_no');
					$this->requestStack->getSession()->remove('cross_yotei_no');
					//文字列を","で分割して取得する
					$array_cross_no = explode (",",$cross_yotei_no);
					$kokyaku_cd = $array_cross_no[0];
					$link_yotei_no = $array_cross_no[1];
				}
				if ($link_yotei_no != ""){
					$sql  = "select [link_yotei_naiyo] = isnull(yotei_naiyo,'')";
					$sql .= " from t_yotei";
					$sql .= " where yotei_no = :yotei_no";
					$paramVal = ["yotei_no" => $link_yotei_no ];
					$rst_yotei = $this->dbs->fetchAssociative($sql,$paramVal);
					if ($rst_yotei != false) {
						$link_yotei_naiyo = $rst_yotei["link_yotei_naiyo"];
					}
				}
			}
		}

		$row = [];

//2025-08-19追加
		$temp_table = "";

//2025-08-19追加
		$table_msg = "t_msg";
		$table_msg_user = "t_msg_user";
		$table_msg_action = "t_msg_action";

		if ($msg_no == "0"){
			//ユーザー情報の取得
			$user = $this->pmm->fn_user_get();

			//顧客詳細画面から一覧へリンク、新規作成の時
			//コメントから、新規作成の時
			//顧客名の取得
			if ($kokyaku_name == "" && $kokyaku_cd !== "0") {
				$mst = $this->cmn->fn_kokyaku_info_get($kokyaku_cd);
				if ($mst != []) {
					$kokyaku_name = $mst["kokyaku_shibu_name"];
				}
			}

			$row = [
					  "id"                      => ""
					 ,"msg_no"          		=> 0
					 ,"msg_title"         		=> $msg_title
					 ,"memo"         			=> $memo
					 ,"from_user_id"         	=> $user["user_id"]
					 ,"from_by"          		=> $user["syain_name"]
					 ,"from_at"                 => ""
					 ,"folder_id"            	=> $folder_id
					 ,"category_id"           	=> ""
					 ,"thema_id"      			=> ""
					 ,"state_id"          		=> ""
					 ,"attached_file"     		=> ""
//2024-11-22(変更)
					 ,"kokyaku_cd"      		=> ($kokyaku_cd == '0') ? "" : $kokyaku_cd
					 ,"anken_no"     			=> $anken_no	//2025-06-06変更
					 ,"link_msg_no" 			=> ""
					 ,"link_comment_no"       	=> ""
					 ,"is_draft"      			=> 0	//0:
					 ,"is_delete"           	=> 0	//0:
					 ,"is_comment_allow"        => 1	//1:コメント許可
					 ,"is_reaction_allow"     	=> 1	//1:リアクション許可
					 ,"is_reserve"           	=> 0	//0:今すぐ投稿 1:次の日時に投稿
					 ,"reserve_at"     			=> ""
					 ,"created_at"              => ""
					 ,"modified_at"             => ""
					 ,"is_reserve_now"			=> "checked"
					 ,"is_reserve_next"			=> ""
					 ,"kokyaku_name"            => $kokyaku_name
					 ,"icon_code"               => $user["icon_code"]
					 ,"icon_code_modi"          => ""
					 ,"modified_user_name"      => ""
					 ,"is_read"                 => 0	//0
					 ,"myclass"                 => ""
					 ,"readclass"               => ""
					 ,"reaction_su"             => 0
					 ,"folder_name"             => ""
					 ,"link_msg_title"          => $link_msg_title
					 ,"link_comment_title"      => $link_comment_title
					 ,"link_yotei_no"   		=> $link_yotei_no
					 ,"link_yotei_naiyo"        => $link_yotei_naiyo
//2024-11-23追加(PIN留め)
//2024-11-30削除	 ,"pin_f"                   => 1
					 ,"is_all_pin"              => 0
					 ,"is_pin"                  => 0
					];
			$mode = "edit";
		} else {
//2025-08-19追加
			$temp_table = $this->fn_temp_table_create($msg_no);
			//$table_msg = $temp_table["msg"];
			$table_msg_user = $temp_table["msg_user"];
			$table_msg_action = $temp_table["msg_action"];

			$sql  = "select";
			$sql .= " a.id";
			$sql .= ",a.msg_no";
			$sql .= ",a.msg_title";
			$sql .= ",a.memo";
			$sql .= ",a.from_user_id";
			$sql .= ",a.from_by";
			$sql .= ",a.from_at";
			$sql .= ",a.folder_id";
			$sql .= ",a.category_id";
			$sql .= ",a.thema_id";
			$sql .= ",a.state_id";
			$sql .= ",a.attached_file";
			$sql .= ",a.kokyaku_cd";
			$sql .= ",anken_no = isnull(a.anken_no,0)";
			$sql .= ",link_msg_no = g1.[msg_no]";
			$sql .= ",link_comment_no = g1.[comment_no]";
			$sql .= ",a.is_draft";
			$sql .= ",a.is_delete";
			$sql .= ",a.is_comment_allow";
			$sql .= ",a.is_reaction_allow";
			$sql .= ",a.is_reserve";
			$sql .= ",a.reserve_at";
			$sql .= ",a.created_at";
			$sql .= ",a.modified_at";
			$sql .= ",(case when isnull(a.is_reserve,0) = 0 then 'checked' else '' end) As is_reserve_now";
			$sql .= ",(case when isnull(a.is_reserve,0) = 1 then 'checked' else '' end) As is_reserve_next";
			$sql .= ",[kokyaku_name] = trim(b.[組合名] + ' ' + isnull(b.[支部名],''))";
//2025-06-05変更([社員CD]がNullの場合、ブランクを返す様に変更)
			$sql .= ",[icon_code] = isnull(c.[社員CD],'')";
			$sql .= ",[icon_code_modi] = isnull(d.[社員CD],'')";
			$sql .= ",[modified_user_name] = d1.[社員名]";
			$sql .= ",e.[is_read]";
			$sql .= ",(case when a.[from_user_id] = :from_user_id then 'my' else '' end) As myclass";
			$sql .= ",(case when ((isnull(a.[modified_user_id],a.[from_user_id]) <> :from_user_id2) ";
			$sql .= " and (isnull(e.[is_read],0) = 0) and (isnull(a.[is_draft],0) = 0))";
			$sql .= " then 'unread' else '' end) as readclass";
			$sql .= ",(select count(s.id) from [t_msg_action] s";
			$sql .= "  where s.[msg_no] = a.[msg_no]";
			$sql .= "    and s.[comment_no] is null";
			$sql .= "    and s.[reaction] <> 0) as [reaction_su]"; 
			$sql .= ",f.folder_name";
			$sql .= ",[link_msg_title] = g2.[msg_title]";
			$sql .= ",[link_comment_title] = (case when g2.[msg_no] is null then '' else format(g1.[comment_no],'0000') end)";
//(スケジュールからメッセージへリンク)スケジュールデータ参照
//			$sql .= ",[link_caller_yotei_no] = g3.yotei_no";
			$sql .= ",[link_yotei_naiyo] = isnull(g3.yotei_naiyo,'')";
//2024-11-23追加(PIN留め)
			$sql .= ",[is_all_pin] = isnull(a.[is_all_pin],0)";
			$sql .= ",[is_pin] = isnull(e.[is_pin],0)";
//2025-05-26追加(メッセージからスケジュールへリンク)メッセージデータ参照
			$sql .= ",a.[link_yotei_no]";
			$sql .= " from {$table} a";
			$sql .= "    left join [M_顧客] b";
			$sql .= "       on b.[顧客CD] = a.[kokyaku_cd]";
//2025-06-05変更([from_user_id]が未登録の場合の対応)
//'			$sql .= "    inner join [M_User] c";
			$sql .= "    left join [M_User] c";
			$sql .= "       on c.[ユーザーID] = a.[from_user_id]";
			$sql .= "    left join [M_User] d";
			$sql .= "       on d.[ユーザーID] = a.[modified_user_id]";
			$sql .= "    left join [C_社員] d1";
			$sql .= "       on d1.[社員CD] = d.[社員CD]";
//2025-08-19変更
//			$sql .= "    left join [t_msg_action] e";
			$sql .= "    left join [{$table_msg_action}] e";
			$sql .= "      on e.[user_id] = :user_id";
			$sql .= "      and e.[msg_no] = a.[msg_no]";
			$sql .= "      and e.[comment_no] is null";
			$sql .= "    left join [m_folder] f";
			$sql .= "      on f.[folder_id] = a.folder_id";
			$sql .= "    left join [t_msg_comment] g1";				//リンク元のコメント
			$sql .= "       on g1.[link_msg_no] = a.[msg_no]";
//2025-08-19変更
//			$sql .= "    left join [t_msg] g2";
			$sql .= "    left join [{$table_msg}] g2";						//リンク元のメッセージ
			$sql .= "       on g2.[msg_no] = g1.[msg_no]";
//			$sql .= "    left join [t_msg_comment] g2";
//			$sql .= "       on  g2.[msg_no] = a.[link_msg_no]";
//			$sql .= "       and g2.[comment_no] = a.[link_comment_no]";
			$sql .= "    left join [t_yotei] g3";
			$sql .= "       on  g3.[yotei_no] = a.[link_yotei_no]";
			$sql .= "    left join [t_yotei] g4";
			$sql .= "       on  g4.[yotei_no] = a.[link_yotei_no]";
			$sql .= " where a.[msg_no] = :msg_no";
			$paramVal  = ["msg_no"        => $msg_no ];
			$paramVal += ["user_id"       => $user_id ];
			$paramVal += ["from_user_id"  => $user_id ];
			$paramVal += ["from_user_id2" => $user_id ];

			$row = $this->dbs->fetchAssociative($sql,$paramVal);
			if ($row == false) {
				$row = [];
			} else {

//2025-08-21変更(引数:[$table_msg_user]を追加)
//2025-05-17変更 別モジュールへ移動
//				$msg_role = $this->fn_msg_role_get($row["msg_no"],$row["from_user_id"]);
				$msg_role = $this->fn_msg_role_get($row["msg_no"],$row["from_user_id"],"",$table_msg_user);
				//2024-11-24追加(既存データで、kokyaku_cdが"0"の場合、ブランクに置き換える)
				//以前、初期値が"0"に設定していたのをブランクに統一したため
				$row["kokyaku_cd"] = ($row["kokyaku_cd"] == '0') ? "" : $row["kokyaku_cd"];
 			}

//2025-06-04追加
			//コメントで案件とリンクしているかチェック
			$sql  = " select count = count(*) ";
			$sql .= " from [t_msg_comment]";
			$sql .= " where [msg_no] = :msg_no";
			$sql .= "   and isnull([link_anken_no],'') <> ''";
			$paramVal = [ "msg_no" => $msg_no ];
			$rst = $this->dbs->fetchAssociative($sql,$paramVal);
			if ($rst != false) {
				if ($rst["count"] > 0) {
					$comment_anken_link_f = 1;
				}
			}

		}
		
//2025-06-04追加
		$row["comment_anken_link_f"] = $comment_anken_link_f;
		
		//リンク情報のセット
		$row["link_tab_no"] = $tab_no;
//2025-06-14変更(新規の条件を付加)
		if ($msg_no == "0" && $link_msg_no != "") {
			$row["link_msg_no"] = $link_msg_no;
			$row["link_comment_no"] = $link_comment_no;
		}

//2025-06-17追加
		$jump_comment_no = "";
		if ($link_msg_no != "" && $link_comment_no != "") {
			$jump_comment_no = $link_comment_no;
		}
		$row["jump_comment_no"] = $jump_comment_no;

//2025-06-01削除
//		if ($link_caller_yotei_no != "") {
//			$row["link_caller_yotei_no"] = $link_caller_yotei_no;
//		}

		if ($link_comment_no != "") {
			$this->requestStack->getSession()->set('link_comment_no',$link_comment_no);
		}

		if ($mode == "view") {
			$m_category_tbl = [];
			$m_thema_tbl = [];
			$m_state_tbl = [];
			
			//カテゴリ
//2025-05-04変更 共通化
			$thema_name = $this->cmn->fn_category_list_cnv("thema",$row["thema_id"]);
			$row["thema_name"] = $thema_name;

			$category_name = $this->cmn->fn_category_list_cnv("category",$row["category_id"]);
			$row["category_name"] = $category_name;

			$state_name = $this->cmn->fn_category_list_cnv("state",$row["state_id"]);
			$row["state_name"] = $state_name;

		}

		//権限の取得
		$row["role"] = $msg_role;

		$row["mode"] = $mode;                                    
		$row["caller"] = "msg";
		if ($row["msg_no"] == 0) {
			$row["new_record"] = 1;						//1:新規
		} else {
			$row["new_record"] = 0;						//0:訂正
		}

		//作成.更新の曜日取得
//2025-05-05共通化
		$row["cre_week"] = $this->cmn->fn_get_date_week($row["from_at"]);
		$row["modi_week"] = $this->cmn->fn_get_date_week($row["modified_at"]);
		

		//顧客リンク
		$kokyaku_path = "";
		if ($row["kokyaku_cd"] != "") {
//2024-11-15変更(初期表示をviewに設定)
			$kokyaku_path = $this->router->generate('app_kokyaku_detail_view',['kokyaku_cd' => $row["kokyaku_cd"],'caller'=>"-" ]);
		}
		$kokyaku_path_def = $this->router->generate('app_kokyaku_detail',['kokyaku_cd' => "@",'caller'=>"-" ]);
		$row["kokyaku_path"] = $kokyaku_path;
		$row["kokyaku_path_def"] = $kokyaku_path_def;

		$link_msg_path = "";
		if ($row["link_msg_no"] != "") {
			$link_msg_path = $this->router->generate('app_msg_detail',['mode' => 'view','msg_no' => $row["link_msg_no"],'caller'=>"-" ]);
		}
		$row["link_msg_path"] = $link_msg_path;

		$link_comment_path = "";
		if ($row["link_comment_no"] != "") {
			$link_comment_path = $this->router->generate('app_msg_detail',['mode' => 'view','msg_no' => $row["link_msg_no"],'caller'=>"-" ]);
		}
		$row["link_comment_path"] = $link_comment_path;

		$link_yotei_path = "";
		if ($row["link_yotei_no"] != "") {
//2025-05-26変更
			if ($this->cmn->fn_sche_role_get($row["link_yotei_no"],$user_id) != "" ){
				$link_yotei_path = $this->router->generate('app_schedule_detail'
				,['mode' => "sview",'sche_no'=> $row["link_yotei_no"],'ndata'=> "_",'caller'=> "-" ]);
			}
		}
		$row["link_yotei_path"] = $link_yotei_path;

//2025-05-23追加
		//日付,ログインID,kind=user
		$ndata = date("Ymd").":".$user_id.":"."user";
		$link_yotei_path = "";
		if ($row["link_yotei_no"] != "") {
			if ($this->cmn->fn_sche_role_get($row["link_yotei_no"],$user_id) != "" ){
				$link_yotei_path = $this->router->generate('app_schedule_detail'
				,['mode' => "sview",'sche_no'=> $row["link_yotei_no"],'ndata'=> $ndata,'caller'=> "-" ]);
			}
		}

//2025-06-01追加
		$new_yotei_path = "";
		if ($msg_no != "0"){
			$new_yotei_path = $this->router->generate('app_schedule'
		 					,["para"     => "none"
		 					,"group"     => "-"
		 					,"kind"      => "mygroup"
		 					,"cal_style" => "gw"
		 					,"fname"     => "-"
		 					,"row_kind"  => "-"
		 					,"row_id"    => $msg_no
		 					,"nav"       => "+"
		 					]);
		}

		//コンボリスト作成
//2025-06-19変更
		if ($mode == "edit") {
			$row["combo_list"] = $this->cbms->combo_list_create(self::cond_id);
			$row["combo_list"] += $this->cbms->combo_list_create("msg_d2");
			//案件コンボ作成
			$para_kokyaku_cd = $row["kokyaku_cd"];
			if (is_null($row["kokyaku_cd"])) {
				$para_kokyaku_cd = "";
			}
			$para_anken_no = $row["anken_no"];
			if (is_null($row["anken_no"])) {
				$para_anken_no = "";
			}
			$combo_anken_para = $para_kokyaku_cd.":".$para_anken_no;
			$data = $this->cbms->combo_create("msg_anken",$combo_anken_para);
			$msg_anken = ["type" => "combogrid","cw" => 140,"pw" => ""  ,"field" => "msg_anken"];
			$data_pro = $this->cbms->combogrid_para_set($msg_anken,$data);
			$row["combo_list"] += ["msg_anken" => $data_pro ];
//			$row["combo_anken_para"] = $combo_anken_para;

			$combo_list = $this->cbms->combo_list_create("msg_d3");
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
			$row["combo_data"] = $combo_data;

//2025-08-11追加
			//本文「添付ファイル」パスを作業用に変更
			$row["memo"] = $this->atm->fn_memo_temp_path_set("msg", $row["memo"], $msg_no);
			$memo_path_dir = "./images/msg/tmp/{$user_id}/";
			$row["memo_path"] = $memo_path_dir;
		} else {
			$combo_list = $this->cbms->combo_list_create("msg_d3");
		}
		//メッセージデータ、宛先フォーム作成

//2025-05-17変更
		//[案件]より遷移してきた場合の対応を追加
		if ($msg_no == "0" && $anken_no != 0) {
			//新規で[案件]より遷移してきた場合、[案件]データよりメンバー取得
//2025-05-17変更 別モジュールへ移動
			$row["data_member"] = $this->pmm->fn_anken_member_get($anken_no);
//2025-06-20復活
		} elseif ($msg_no == "0" && $link_yotei_no != "") {
			$key_para = ["new_record" => 0, "yotei_no" => $link_yotei_no, "kind" => "" ];
			$yotei_sankasha_list = $this->pmm->fn_sankasha_member_get("yotei",$key_para);
			$row["data_member"] = $yotei_sankasha_list;

		} else {
			//メッセージ宛先リスト(参加者)
//2025-05-17変更 別モジュールへ移動
			$key_para = ["new_record" => ($msg_no == 0 ? 1 : 0), "msg_no" => $msg_no ];
//2025-08-21追加(パラメータ追加)
			$key_para += ["msg_user" => $table_msg_user ];
			$row["data_member"] = $this->pmm->fn_sankasha_member_get("msg",$key_para);
		}
//2025-08-21追加(msgの参加者一覧(コメントの名称取得で使用するため))
		if ($this->requestStack->getSession()->has('msg_member')) {
			$this->requestStack->getSession()->remove('msg_member');
		}
		$this->requestStack->getSession()->set('msg_member',$row["data_member"]["sankasha_member_list"]);

/* 2025-06-01削除
		if ($link_caller_yotei_no != "") {
//2025-05-15変更
//			$yotei_sankasha_list = $this->ptm->fn_sankasha_list_get("yotei","","",$link_yotei_no);
			$yotei_sankasha_list = $this->pmm->fn_sankasha_list_get("yotei","","",$link_caller_yotei_no);
			$row["data_member"] = $yotei_sankasha_list;
//2025-02-20削除
//		} elseif ($link_comment_no != "") {
//			$comment_sankasha_list = $this->ptm->fn_sankasha_list_get("msg",$link_msg_no,$link_comment_no,"");
//			$row["data_member"] = $comment_sankasha_list;
		}
*/

		//link1
		[$link_msg_cap,$link_msg_url] = $this->ptm->fn_link_split($row['link_msg_no']);
		$row["link_msg_cap"] = $link_msg_cap;
		$row["link_msg_url"] = $link_msg_url;
		//link2
		[$link_comment_cap,$link_comment_url] = $this->ptm->fn_link_split($row['link_comment_no']);
		$row["link_sub1_cap"] = $link_comment_cap;
		$row["link_sub1_url"] = $link_comment_url;

		$url = $this->router->generate("app_msg_detail"
								,['mode' => "view" ,'msg_no' => $msg_no ]);
		$row["url"] = $url;
		
		//案件 編集用
/* 2025-06-12変更
		$a_root = "app_anken_detail";
		$anken_path_edit = $this->router->generate($a_root ,['anken_no' => 0 ]);
		$row += ["anken_path_edit" => $anken_path_edit ];

		//案件 表示用
		$a_root = "app_anken_view";
		$anken_path_view = $this->router->generate($a_root ,['anken_no' => "@1" ]);
		$row += ["anken_path_view" => $anken_path_view ];
*/
		if ($row["anken_no"] == 0) {
			$a_root = "app_anken_detail";
		} else {
			$a_root = "app_anken_view";
		}

		$row["link_anken_path"] = $this->router->generate($a_root, ['anken_no' => $row["anken_no"] ]);
//seedtech add
		$row["link_todo_path"] = $this->router->generate('app_todo_view',['anken_no' => $row["anken_no"] ]);

		// 添付ファイル初期設定
		$attached_file_dir = "../../files/attached/msg";
//2025-08-23
		if ($msg_no != 0) {
			// 訂正の場合は、msg_noを付加して フォルダーを設定
			$folder_msg = sprintf('%08d', $msg_no);
			$attached_file_dir .= "/{$folder_msg}";
		} else{
			$folder_msg = "tmp/".$user_id;
		}
		$folder_msg = "tmp/".$user_id;


		//添付ファイル
		$row["attach"] = $this->atm->init($attached_file_dir);

		// メッセージ既読ユーザーリストの作成
//2025-05-17変更 別モジュールへ移動
		$kidoku_list = $this->fn_read_list_get($msg_no);
		// メッセージリアクションユーザーリストの作成
//2025-08-21変更(引数:[$table_msg_action]を追加)
//2025-05-17変更 別モジュールへ移動
//		$reation_list = $this->fn_reaction_list_get($msg_no,0);
		$reation_list = $this->fn_reaction_list_get($msg_no,0,$table_msg_action);

		// ログインユーザー 権限取得
    	$edit_role = $this->ptm->gfn_login_user_role();

		// 編集ボタン 使用可能判定 (管理者Gは除く)
		$is_msg_edit = "0";

		if (($row["myclass"] == "my" || $msg_role == 2)) {
			$is_msg_edit = "1";
		}

		// 返信ボタン 使用可能判定
		$is_msg_post = "0";
//2025-05-27 △にも許可
//		 	|| ($row["is_comment_allow"] == 1 && $msg_role > 0)) {
		if (($row["myclass"] == "my") || ($msg_role >= 2)
		 	|| ($row["is_comment_allow"] == 1 && $msg_role >= 0)) {
			$is_msg_post = "1";
		}

		// リアクションボタン 使用可能判定
		$is_msg_reaction = "0";
		if ($row["myclass"] == "my" || $row["is_reaction_allow"] == 1 && $msg_role >= 0) {
			$is_msg_reaction = "1";
		}
		// 削除ボタン 使用可能判定
		$is_msg_delete = "0";
//2023-06-04変更 (管理者Gの条件を追加)
		if ($row["myclass"] == "my" || $msg_role == 2) {
			$is_msg_delete = "1";
		}

		// コメント用 返信ボタン/リアクションボタン 使用可能判定
		$is_comment_post = "0";
//メンバー以上可
		$is_comment_reaction = "0";
		if (($msg_role >= 2)
		 	|| ($row["is_comment_allow"] == 1 && $msg_role > 0)) {
			$is_comment_post = "1";
			$is_comment_reaction = "1";
		}

		//リンクコピー情報の取得
		$link_list = $this->ptm->fn_link_get();

		//コメント情報の取得
		if ($mode == "view") {
//2025-05-17変更 別モジュールへ移動
//			$comment_ret = $this->comment_detail_get("view",$msg_no);
			$comment_ret = $this->comment_detail_get("view",$msg_no);
			$comment_row = $comment_ret["comment"];
			$comment_para = $comment_ret["comment_para"];
			$comment_btn = $comment_ret["comment_btn"];
			$comment_paginate = $comment_ret["comment_paginate"];
			$comment_btn["edit_role"] = $edit_role;
			$comment_btn["myclass"] = $row["myclass"] ;
//2024-12-28追加
			$order_post = $comment_ret["order_post"];
			$order_reply = $comment_ret["order_reply"];
//2025-03-19追加
			$all_summary_f = $comment_ret["all_summary_f"];
		} else {
			$comment_row = [];
			$comment_para = [];
			$comment_btn = [];
			$comment_paginate = [];
//2024-12-28追加
			$order_post = "";
			$order_reply = "";
//2025-03-19追加
			$all_summary_f = 0;
		}

		//(キャンセルボタン)直前のパスの取得
//2024-11-06修正
//		$back_path = $this->bm->getBbreadCrumbPath("msg_new");
/* 2025-06-11削除
		if ($anken_no == "0") {
			$back_path = $this->bm->getBbreadCrumbPath("msg_new");
		} else {
			$back_path = $this->bm->getBbreadCrumbPath("","all");
		}
*/
		$back_path = $this->bm->getBbreadCrumbPath("");

//リンクコピー用URL
		$link_url = "";
		if ($msg_no != 0) {
			$link_url = $this->router->generate('app_global_link'
										,['kind' => 'm', 'key_no' => $msg_no ]);
		}

//2024-11-23追加(PIN留め)
		//0:TOPにPIN留めOFF
		$pin_f = 0;
		if ($row["is_all_pin"] == 1) {
			//1:全社員にPIN留め
			$pin_f = 9;
		} elseif ($row["is_pin"] == 1) {
			//1:TOPにPIN留めON
			$pin_f = 1;
		}
//2024-11-30削除($btn_rowに追加する様に変更)
//		$row["pin_f"] = $pin_f;

		//ボタン表示用
		$btn_row = [];
		$btn_row["msg_no"] = $msg_no;
		$btn_row["msg_title"] = $row["msg_title"];
		$btn_row["role"] = $msg_role;
		$btn_row["mode"] = $mode;                                    
		$btn_row["is_draft"] = $row["is_draft"];
		$btn_row["is_msg_edit"] = $is_msg_edit;
		$btn_row["is_msg_post"] = $is_msg_post;
		$btn_row["is_msg_delete"] = $is_msg_delete;
//2024-11-30追加
		$btn_row["pin_f"] = $pin_f;
		$btn_row["edit_role"] = $edit_role;
//2025-05-26追加
		$btn_row["link_yotei_no"] = $row["link_yotei_no"];
		$btn_row["link_yotei_naiyo"] = $row["link_yotei_naiyo"];
		$btn_row["link_yotei_path"] = $link_yotei_path;
//2025-06-01追加
		$btn_row["new_yotei_path"] = $new_yotei_path;

		//m_folder件数取得
		$sql =  "select cnt = count(*)";
		$sql .= " from [m_folder]";
		$sql .= " where isnull(is_delete,0) = :is_delete";
		$sql .= "   and folder_id <> :folder_id";
		$paramVal  = [ "is_delete" => 0 ];
		$paramVal += [ "folder_id" => 1 ];
		$rst = $this->dbs->fetchAssociative($sql,$paramVal);
		$folder_count = $rst["cnt"];

//パンくず追加
		$breadcrumb = $this->requestStack->getSession()->get('bread_array');
		$active_menu = $this->requestStack->getSession()->get('acitive_menu');

		return [ "msg"                 => $row
				,"comment"             => $comment_row
				,"comment_para"        => $comment_para
				,"comment_btn"         => $comment_btn
				,"comment_paginate"    => $comment_paginate
				,"btn"                 => $btn_row
				,"kidoku_list"         => $kidoku_list
				,"reation_list"        => $reation_list
				,"edit_role"           => $edit_role
				,"is_comment_reaction" => $is_comment_reaction
				,"link_list"           => $link_list
				,"back_path"           => $back_path
				,"page_id"             => $page_id
				,"combo_list"		   => $combo_list
				,"folder_count"        => $folder_count
				,"link_url"            => $link_url
				,"breadcrumb"          => $breadcrumb
				,"active_menu"         => $active_menu
//2024-12-28追加
				,"order_post"          => $order_post
				,"order_reply"         => $order_reply
//2025-03-19追加
				,"all_summary_f"       => $all_summary_f
//2025-06-02追加
				,"link_kind"           => $link_kind
				];

	}

	//メッセージ詳細 削除処理
	public function msg_detail_delete($msg_no) {

		//メッセージ詳細トラン削除
		$table = "t_msg";

		$update_time = new \DateTime();
		$update_time = $update_time->format('Y-m-d H:i:s');
		$user_id = $this->user->getUserId();

		//項目設定
		$dataVal  = ["is_delete"        => 1];
		$dataVal += ["modified_user_id" => $user_id ];
		$dataVal += ["modified_at"      => $update_time ];
//2024-11-22追加(リンク情報のクリア)
		$dataVal += ["anken_no"         => null ];
		$dataVal += ["link_msg_no"      => null ];
		$dataVal += ["link_comment_no"  => null ];
		$dataVal += ["link_yotei_no"    => null ];

		//更新実行
		//パラメータ設定
		$paramKey = ["msg_no" => $msg_no ];

		//更新実行
		$ret = $this->dbs->update($table,$dataVal,$paramKey);

//2024-11-22追加(リンク情報のクリア)
//他メッセージのlink_msg_noに該当msg_noが登録されている場合
//他メッセージのlink_msg_no,link_comment_noをクリア
		//項目設定
		$dataVal  = ["modified_user_id" => $user_id ];
		$dataVal += ["modified_at"      => $update_time ];
		$dataVal += ["link_msg_no"      => null ];
		$dataVal += ["link_comment_no"  => null ];

		//更新実行
		//パラメータ設定
		$paramKey = ["link_msg_no" => $msg_no ];

		//更新実行
		$ret = $this->dbs->update($table,$dataVal,$paramKey);

//スケジュールのlink_msg_noに該当msg_noが登録されている場合
//スケジュールのlink_msg_no,link_comment_noをクリア
/* 2025-06-11削除
		$table = "t_yotei";
		//項目設定
		$dataVal  = ["modified_user_id" => $user_id ];
		$dataVal += ["modified_at"      => $update_time ];
		$dataVal += ["link_msg_no"      => null ];
		$dataVal += ["link_comment_no"  => null ];
//2024-12-05追加
		$dataVal += ['kokyaku_cd'       => null ];

		//更新実行
		//パラメータ設定
		$paramKey = ["link_msg_no" => $msg_no ];

		//更新実行
		$ret = $this->dbs->update($table,$dataVal,$paramKey);
*/		
//2024-12-18追加
		//***************************************
		//メッセージ詳細(アクション)更新
		//登録済み 同一メッセージNO/コメントNO を削除
		//***************************************
		$table_action = "t_msg_action";

		$paramVal = ["msg_no" => $msg_no ];
		$ret = $this->dbs->delete($table_action,$paramVal);

// 2025-03-30追加(ログ出力) -----------
		$para_l = [];
		$para_l["action"] = "削除";
		$para_l["msg_no"] = $msg_no;
		$this->fn_log_out($para_l);
//-------------------------------------

		//メッセージ再取得
		$data = $this->msg_detail_get("view",$msg_no);

		return ["status" => true ,"data" => $data ];

	}

	//メッセージ復元
	public function msg_detail_restore($msg_no) {

		$table = "t_msg";

		$update_time = new \DateTime();
		$update_time = $update_time->format('Y-m-d H:i:s');
		$user_id = $this->user->getUserId();

		//項目設定
		$dataVal  = ["is_delete"        => 0 ];
		$dataVal += ["modified_user_id" => $user_id ];
		$dataVal += ["modified_at"      => $update_time ];

		//更新実行
		//パラメータ設定
		$paramKey = ["msg_no" => $msg_no ];

		//更新実行
		$ret = $this->dbs->update($table,$dataVal,$paramKey);

		//メッセージ再取得
		$data1 = $this->msg_detail_get("view",$msg_no);
		$data2 = $this->comment_detail_get("view",$msg_no);

// 2025-03-30追加(ログ出力) -----------
		$para_l = [];
		$para_l["action"] = "復元";
		$para_l["subject"] = $data1["msg"]["msg_title"];
		$para_l["msg_no"] = $msg_no;
		$this->fn_log_out($para_l);
//-------------------------------------

		$ret = [ "status" => true, "data1" => $data1, "data2" => $data2 ];

		return $ret;

	}

//2024-11-23追加
	//メッセージ詳細 PIN登録処理
	public function msg_detail_pin($msg_no,$pin_f) {

		//ログインユーザーよりユーザー情報の取得
		$user_id = $this->user->getUserId();

		//**********************************
		//メッセージ更新
		$table = "t_msg";

		if ($pin_f == 9) {
			$is_all_pin = 1;
		} else {
			$is_all_pin = 0;
		}

		$dataVal  = ["is_all_pin" => $is_all_pin ];
		$paramKey = ["msg_no" => $msg_no ];

		//更新実行
		$ret = $this->dbs->update($table,$dataVal,$paramKey);

// 2025-03-30追加(ログ出力) -----------
		if ($pin_f == 9) {
			$para_l = [];
			$para_l["action"] = "PIN留め";
			$para_l["msg_no"] = $msg_no;
			$this->fn_log_out($para_l);
		}
//-------------------------------------
		
		//**********************************
		//メッセージ詳細(アクション)更新
		$table = "t_msg_action";

		if ($pin_f == 9) {
			$is_pin = 0;
		} else {
			$is_pin = $pin_f;
		}

		$sql = "select user_id"; 
		$sql .= " from {$table}";
		$sql .= " where [msg_no] = :msg_no";
		$sql .= "   and [user_id] = :user_id";
		$paramVal  = ["msg_no"  => $msg_no ];
		$paramVal += ["user_id" => $user_id ];
		$rst = $this->dbs->fetchAssociative($sql,$paramVal);
		if ($rst == false) {
		//追加
			$dataVal  = ["user_id" 	  => $user_id ];
			$dataVal += ["msg_no"     => $msg_no ];
			$dataVal += ["comment_no" => null ];
			$dataVal += ["reaction"   => 0 ];
			$dataVal += ["is_read"    => 0 ];
			$dataVal += ["is_pin"     => $pin_f ];
			//追加実行
			$ret = $this->dbs->insert($table,$dataVal);
		} else {
		//更新
			$dataVal  = ["is_pin" => $is_pin ];

			$paramKey  = ['msg_no'  => $msg_no ];
			$paramKey += ['user_id' => $user_id ];
			//更新実行
			$ret = $this->dbs->update($table,$dataVal,$paramKey);
		}

		return ["status" => true ];

	}


	//コメント削除
	public function msg_detail_comment_delete($msg_no,$comment_no) {

		$table = "t_msg_comment";

		$update_time = new \DateTime();
		$update_time = $update_time->format('Y-m-d H:i:s');
		$user_id = $this->user->getUserId();

		//項目設定
		$dataVal  = ["is_delete"        => 1];
		$dataVal += ["modified_user_id" => $user_id ];
		$dataVal += ["modified_at"      => $update_time ];
//2024-11-22追加(リンク情報のクリア)
		$dataVal += ["link_msg_no"      => null ];
		$dataVal += ["link_yotei_no"    => null ];

		//更新実行
		//パラメータ設定
		$paramKey  = ["msg_no"      => $msg_no ];
		$paramKey += ["comment_no"  => $comment_no ];

		//更新実行
		$ret = $this->dbs->update($table,$dataVal,$paramKey);

//2024-11-22追加(リンク情報のクリア)
//他メッセージのlink_msg_no,link_comment_noに該当msg_no,comment_noが登録されている場合
//他メッセージのlink_msg_no,link_comment_noをクリア
		$table = "t_msg";
		//項目設定
		$dataVal  = ["modified_user_id" => $user_id ];
		$dataVal += ["modified_at"      => $update_time ];
		$dataVal += ["link_msg_no"      => null ];
		$dataVal += ["link_comment_no"  => null ];

		//更新実行
		//パラメータ設定
		$paramKey  = ["link_msg_no"     => $msg_no ];
		$paramKey += ["link_comment_no" => $comment_no ];

		//更新実行
		$ret = $this->dbs->update($table,$dataVal,$paramKey);

//スケジュールのlink_msg_no,link_comment_noに該当msg_no,comment_noが登録されている場合
//スケジュールのlink_msg_no,link_comment_noをクリア
		$table = "t_yotei";
		//項目設定
		$dataVal  = ["modified_user_id" => $user_id ];
		$dataVal += ["modified_at"      => $update_time ];
		$dataVal += ["link_msg_no"      => null ];
		$dataVal += ["link_comment_no"  => null ];

		//更新実行
		//パラメータ設定
		$paramKey  = ["link_msg_no"     => $msg_no ];
		$paramKey += ["link_comment_no" => $comment_no ];

		//更新実行
		$ret = $this->dbs->update($table,$dataVal,$paramKey);

//2024-12-18追加
		//***************************************
		//メッセージ詳細(アクション)更新
		//登録済み 同一メッセージNO/コメントNO を削除
		//***************************************
		$table_action = "t_msg_action";

//2025-02-19修正 
//		$paramVal = ["msg_no" => $msg_no ];
		$paramKey = ["msg_no" => $msg_no ];
		$paramKey += ["comment_no" => $comment_no ];
//2025-02-19修正
		$ret = $this->dbs->delete($table_action,$paramKey);
		
// 2025-03-30追加(ログ出力) -----------
		$para_l = [];
		$para_l["action"] = "削除";
		$para_l["msg_no"] = $msg_no;
		$para_l["comment_no"] = $comment_no;
		$this->fn_log_out($para_l);
//-------------------------------------

		//メッセージ再取得
		$data = $this->comment_detail_get("view",$msg_no);
		
		return $data;

	}

//2025-08-19変更(引数追加)
	//コメント情報の取得
	public function comment_detail_get($mode,$msg_no,$comment_no="",$temp_table=""){
//2025-08-19追加
		if ($temp_table == "") {
			//テンポラリーテーブル作成
			$temp_table = $this->fn_temp_table_create($msg_no);
		}
		//$table_msg = $temp_table["msg"];
		$table_msg = "t_msg";
		$table_msg_comment = $temp_table["msg_comment"];
		$table_msg_user = $temp_table["msg_user"];
		$table_msg_action = $temp_table["msg_action"];

		//ページ番号初期セット
		$pageMax = self::pageMax;
		$msg_page = 1;
//2025-01-10追加
		$next_f = 0;
		if ($this->requestStack->getSession()->has('msg_page')) {
			$msg_page = intval($this->requestStack->getSession()->get('msg_page'));
			$this->requestStack->getSession()->remove('msg_page');
		}
//2024-12-28追加
		$comment_order = 0;
		if ($this->requestStack->getSession()->has('comment_order')) {
			$comment_order = intval($this->requestStack->getSession()->get('comment_order'));
			$this->requestStack->getSession()->remove('comment_order');
		}

//2025-03-19追加
		$comment_all_summary_f = 0;
		if ($this->requestStack->getSession()->has('comment_all_summary_f')) {
			$comment_all_summary_f = intval($this->requestStack->getSession()->get('comment_all_summary_f'));
			$this->requestStack->getSession()->remove('comment_all_summary_f');
		}

		//ログインユーザーよりユーザー情報の取得
		$login_user_id = $this->user->getUserId();
		$login_user_name = $this->user->getUserName();
		$del_limit_dt = date("Y-m-d",strtotime("-1 month"));

		$row = [];
//2025-08-21変更
//		$table = "t_msg_comment";
		$table = $table_msg_comment;
//2025-03-09追加
		$sql = "";
//2025-03-21追加
		$sql_t = "";

		if ($mode == "reply"){
			if ($comment_no != "") {
				$sql  = "select reply_no";
				$sql .= " from {$table}";
				$sql .= " where comment_no = :comment_no";
				$paramVal = ["comment_no" => $comment_no ];
				$rst = $this->dbs->fetchAssociative($sql,$paramVal);

				$reply_no = $comment_no;
			} else {
				$reply_no = 0;
			}

//2024-11-26追加
			$sql  = "select from_user_id";
//2025-08-19変更
//			$sql .= " from [t_msg]";
			$sql .= " from [{$table_msg}]";
			$sql .= " where msg_no = :msg_no";
			$paramVal = ["msg_no" => $msg_no ];
			$rst_msg = $this->dbs->fetchAssociative($sql,$paramVal);
			$msg_from_user_id = $rst_msg["from_user_id"];
			//ユーザー情報の取得
			$user = $this->pmm->fn_user_get();
			
//2025-08-11追加
			//本文「添付ファイル」作業用フォルダのクリア
			$this->atm->fn_memo_temp_clear("msg");

			$row[0] = [
					  "id"                      => ""
					 ,"comment_no"        		=> 0
					 ,"memo"         			=> ""
					 ,"from_user_id"         	=> $user["user_id"]
					 ,"from_by"          		=> $user["syain_name"]
					 ,"from_at"                 => ""
					 ,"attached_file"     		=> ""
					 ,"msg_no"      		    => $msg_no
					 ,"reply_no"      		    => $reply_no
					 ,"chain_key"     			=> ""
					 ,"link_msg_no" 			=> ""
					 ,"link_yotei_no"       	=> ""
					 ,"is_comment_allow"    	=> ""
					 ,"is_reaction_allow"    	=> ""
					 ,"msg_from_user_id"    	=> $msg_from_user_id
					 ,"link_yotei_naiyo"       	=> ""
					 ,"is_delete"           	=> 0	//0:
					 ,"created_at"              => ""
					 ,"modified_user_id"        => ""
					 ,"modified_at"             => ""
//2025-01-04追加
					 ,"update_at"               => ""
					 ,"read_flg"                => ""
					 ,"mode"                    => $mode
					 ,"myclass"                 => ""
					 ,"readclass"               => ""
					 ,"reaction_su"             => 0
					 ,"icon_code"               => $user["icon_code"]
					 ,"icon_code_modi"          => ""
					 ,"modified_user_name"      => ""
					 ,"msg_link_no"             => ""
					 ,"yotei_link_no"           => ""
//2025-01-06追加
					 ,"is_read"                 => 0
//2025-05-08追加
//2025-05-31削除	 ,"link_anken_no"           => ""
//2025-05-31追加
					 ,"link_anken_title"        => ""
					 ,"anken_link_no"           => ""
					];
		} else {

			//返信関連順
//2025-03-18追加
			$sql  = "";
			if ($comment_order !== 0) {
				$temp_comment = "#temp_comment";
				$sql_t  = " set nocount on;";
				$sql_t .= "IF EXISTS (select name from tempdb..sysobjects";
				$sql_t .= " WHERE id = OBJECT_ID('tempdb..".$temp_comment."') and type = 'U')";
				$sql_t .= " drop table [{$temp_comment}];";
				//$ret = $this->dbs->executeUpdate($sql);
				
				$sql_t .= "select";
				$sql_t .= " oya_code = left(a.chain_key,3)";
				$sql_t .= ",update_at = max(isnull(a.modified_at,a.created_at))";
				$sql_t .= " into [{$temp_comment}]";
				$sql_t .= " from {$table} a";
				$sql_t .= " where a.msg_no = :msg_no";
				$sql_t .= " group by left(a.chain_key,3);";
				
				$paramVal = ["msg_no" => $msg_no ];
			}
			$sql  = $sql_t;
			$sql .= "select";
			$sql .= " a.id";
			$sql .= ",a.comment_no";
			$sql .= ",a.memo";
			$sql .= ",a.from_user_id";
			$sql .= ",a.from_by";
			$sql .= ",a.from_at";
			$sql .= ",a.attached_file";
			$sql .= ",a.msg_no";
			$sql .= ",isnull(a.reply_no,0) As reply_no";
			$sql .= ",a.chain_key";
			$sql .= ",a.link_msg_no";
			$sql .= ",a.link_yotei_no";
//2024-11-11追加
			$sql .= ",d.is_comment_allow";
			$sql .= ",d.is_reaction_allow";
			$sql .= ",msg_from_user_id = d.from_user_id";

			$sql .= ",a.is_delete";
			$sql .= ",a.created_at";
			$sql .= ",a.modified_user_id";
			$sql .= ",a.modified_at";
//2025-01-04追加
			$sql .= ",update_at = isnull(a.modified_at,a.from_at)";

//2025-01-08削除
//			$sql .= ",(case when isnull(e.is_read,0) = 0 then '未' else '' end) As read_flg";
			$sql .= ",'edit' As mode";
			$sql .= ",(case when a.from_user_id = :login_user_id then 'my' else '' end) As myclass";
			$sql .= ",(case when (((a.[from_user_id] <> :from_user_id)";
			$sql .= "   or (a.[from_user_id] <> isnull(a.[modified_user_id],a.[from_user_id])))";
//2025-03-06変更
			$sql .= "         and (isnull(e.[is_read],0) = 0)) then 'unread' else '' end) as readclass";
//			$sql .= "         and (isnull(e.[is_read],1) = 0)) then 'unread' else '' end) as readclass";
			$sql .= ",(select count(s.id) from [t_msg_action] s";
			$sql .= "  where s.msg_no = a.msg_no";
			$sql .= "    and s.comment_no = a.comment_no";
			$sql .= "    and s.reaction <> 0) as reaction_su";
			$sql .= ",[icon_code] = b.[社員CD]";
			$sql .= ",[icon_code_modi] = c.[社員CD]";
			$sql .= ",[modified_user_name] = c1.[社員名]";

//			$sql .= ",[msg_link_no] = e1.msg_no";
			$sql .= ",[msg_link_no] = a.link_msg_no";
			
			$sql .= ",[link_msg_title] = isnull(e1.[msg_title],'')";
//			$sql .= ",[link_yotei_no] = e2.yotei_no";
//			$sql .= ",e2.yotei_no As yotei_link_no";
			$sql .= ",[link_yotei_naiyo] = e2.yotei_naiyo";
//2025-01-06追加
			$sql .= ",is_read = isnull(e.is_read,0)";
//2025-05-31削除
//2025-05-08追加
//			$sql .= ",a.link_anken_no";
			$sql .= ",e3.[案件タイトル] As link_anken_title";
			$sql .= ",e3.[案件NO] As anken_link_no";
			$sql .= " from {$table} a";
			//返信関連順
			if ($comment_order !== 0) {
				$sql .= "    inner join [{$temp_comment}] tmp";
				$sql .= "       on tmp.[oya_code] = left(a.chain_key,3)";
			}
			$sql .= "    inner join [M_User] b";
			$sql .= "       on b.[ユーザーID] = a.[from_user_id]";
			$sql .= "    left join [M_User] c";
			$sql .= "       on c.[ユーザーID] = a.[modified_user_id]";
			$sql .= "    left join [C_社員] c1";
			$sql .= "       on c1.[社員CD] = c.[社員CD]";
//2025-08-19変更
//			$sql .= "    left join [t_msg] d";
			$sql .= "    left join [{$table_msg}] d";
			$sql .= "      on d.msg_no = a.msg_no";
//2025-08-19変更
//			$sql .= "    left join [t_msg_action] e";
			$sql .= "    left join [{$table_msg_action}] e";
			$sql .= "      on e.user_id = :user_id";
			$sql .= "      and e.msg_no = a.msg_no";
			$sql .= "      and e.comment_no = a.comment_no";
//			$sql .= "    left join [t_msg] e1";
//			$sql .= "      on  e1.link_msg_no = a.msg_no";
//			$sql .= "      and e1.link_comment_no = a.comment_no";
//2025-08-19変更
//			$sql .= "    left join [t_msg] e1";
			$sql .= "    left join [{$table_msg}] e1";
			$sql .= "      on  e1.msg_no = a.link_msg_no";
			$sql .= "    left join [t_yotei] e2";
			$sql .= "      on  e2.yotei_no = a.link_yotei_no";
			$sql .= "    left join [T_案件] e3";
			$sql .= "      on  e3.[案件NO] = a.link_anken_no";
			$sql .= " where a.[msg_no] = :msg_no";
			$sql .= "   and isnull(d.is_delete,0) = :is_delete";
			if ($comment_no != "") {
				$sql .= " and a.comment_no = :comment_no";
			}
			$sql .= "  and (isnull(a.is_delete,0)  = :is_delete1";
			$sql .= "  or (isnull(a.is_delete,0)  = :is_delete2";
			$sql .= "  and (a.modified_at  >= :del_limit_dt)))";
//2024-12-24変更
			//$sql .= " order by isnull(a.modified_at,a.from_at) desc";
//2024-12-28変更
			if ($comment_order == 0) {
				//投稿日時順
				$sql .= " order by isnull(a.modified_at,a.from_at) desc";
			} else {
				//返信関連順
//2025-03-18変更
//				$sql .= " order by left(a.chain_key,3) + (case when a.reply_no=0 then 0 else 1 end) desc";
//				$sql .= ",isnull(a.modified_at,a.created_at) desc,a.reply_no desc";
				$sql .= " order by tmp.update_at desc,tmp.oya_code ,(case when a.reply_no=0 then 0 else 1 end) desc";
				$sql .= ",isnull(a.modified_at,a.created_at) desc,a.reply_no desc";
			}
			$paramVal  = ["msg_no"        => $msg_no ];
			if ($comment_no != "") {
				$paramVal += ["comment_no" => $comment_no ];
			}
			$paramVal += ["is_delete"     => 0 ];
			$paramVal += ["is_delete1"    => 0 ];	//0:投稿データ
			$paramVal += ["is_delete2"    => 1 ];	//1:削除データ
			$paramVal += ["del_limit_dt"  => $del_limit_dt ];	//1か月以前
			$paramVal += ["login_user_id" => $login_user_id ];
			$paramVal += ["user_id"       => $login_user_id ];
			$paramVal += ["from_user_id"  => $login_user_id ];

			//link comment_no が存在する場合、該当comment_noのpageを求める
			$link_comment_no = 0;
			if ($this->requestStack->getSession()->has('link_comment_no')) {
				$link_comment_no = $this->requestStack->getSession()->get('link_comment_no');
				$this->requestStack->getSession()->remove('link_comment_no');
				$row = $this->dbs->fetchAllAssociative($sql,$paramVal);
				if ($row != false) {
					for ($i = 0;$i < count($row);$i++) {
						if ($link_comment_no == $row[$i]["comment_no"]) {
							$msg_page = ceil(($i+1) / $pageMax);
							break;
						}
					}
				}
			}

//2025-01-09追加
			//並び順[返信関連順]の場合は、コメントの返信は最大３件までで計算する
			//コメントの返信表示の初期値が３件のため <--コメントをあとで修正
			$msg_page_info = [];
			if ($this->requestStack->getSession()->has('msg_page_info')) {
				$msg_page_info = $this->requestStack->getSession()->get('msg_page_info');
			}
			$page_s_rec = 0;
			if ($msg_page_info != []) {
				if (array_key_exists("k".$msg_page,$msg_page_info)) {
					$page_s_rec = $msg_page_info["k".$msg_page]["page_s_rec"];
				} elseif ($msg_page != 1){
					$page_s_rec = $msg_page_info["k".($msg_page-1)]["page_s_rec"];
					$page_s_rec += $msg_page_info["k".($msg_page-1)]["page_num"];
				}
			} else {
				if ($link_comment_no != 0) {
					$page_s_rec = ($msg_page-1) * $pageMax;
				}
			}

//2025-03-21追加↓
//[comment_no]指定なし(ページ送り)の場合のみ、ページ情報の計算を行う。
//[comment_no]指定あり(訂正呼出し)の場合は何もしない)
			if ($comment_no == "") {
				//******  ページ情報の計算  ******

				$pageMax_reply = self::pageMax_reply;
//				$pageMax_num = 0;

//2025-03-21変更(スピードアップのため)
//				$sql2 = $sql . " OFFSET {$page_s_rec} ROWS";

				$sql2  = $sql_t;
				$sql2 .= " select";
				$sql2 .= " a.reply_no";
				$sql2 .= " from {$table} a";
				//返信関連順
				if ($comment_order !== 0) {
					$sql2 .= "    inner join [{$temp_comment}] tmp";
					$sql2 .= "       on tmp.[oya_code] = left(a.chain_key,3)";
				}
//2025-08-19変更
//				$sql2 .= "    left join [t_msg] d";
				$sql2 .= "    left join [{$table_msg}] d";
				$sql2 .= "      on d.msg_no = a.msg_no";
				$sql2 .= " where a.[msg_no] = :msg_no";
				$sql2 .= "   and isnull(d.is_delete,0) = :is_delete";
				if ($comment_no != "") {
					$sql2 .= " and a.comment_no = :comment_no";
				}
				$sql2 .= "  and (isnull(a.is_delete,0)  = :is_delete1";
				$sql2 .= "  or (isnull(a.is_delete,0)  = :is_delete2";
				$sql2 .= "  and (a.modified_at  >= :del_limit_dt)))";
				if ($comment_order == 0) {
					//投稿日時順
					$sql2 .= " order by isnull(a.modified_at,a.from_at) desc";
				} else {
					//返信関連順
//2025-03-18変更
//					$sql .= " order by left(a.chain_key,3) + (case when a.reply_no=0 then 0 else 1 end) desc";
//					$sql .= ",isnull(a.modified_at,a.created_at) desc,a.reply_no desc";
					$sql2 .= " order by tmp.update_at desc,tmp.oya_code ,(case when a.reply_no=0 then 0 else 1 end) desc";
					$sql2 .= ",isnull(a.modified_at,a.created_at) desc,a.reply_no desc";
				}
				$sql2 .= " OFFSET {$page_s_rec} ROWS";
//2025-03-21追加↑
				//$sql2 = $sql . " OFFSET {$page_s_rec} ROWS";

				$row2 = $this->dbs->fetchAllAssociative($sql2,$paramVal);

				if ($row2 != false) {
					$break_f = 0;
					for ($i = 0;$i < count($row2);$i++) {
						//$pageMax_num++;
						if ($comment_order == 0) {
							if (($i + 1) >= $pageMax) {
								$break_f = 1;
							}
						} else {
							if (($i + 1) >= $pageMax_reply && $row2[$i]["reply_no"] === "0") {
								$pageMax = $i + 1;
								$break_f = 1;
							}
						}
						if ($break_f == 1) {
							$next_f = (($i+1) < count($row2)) ? 1 : 0;
							break;
						}
					}
				}

				//page処理
//2025-01-09変更
//				$start_row = ($msg_page - 1) * $pageMax;
				if (array_key_exists($msg_page,$msg_page_info) == false) {
					$msg_page_info["k".$msg_page] = [];
				}
				$msg_page_info["k".$msg_page] = [
											 "page_s_rec" => $page_s_rec
											,"page_num"   => $pageMax
											,"next_f"     => $next_f
											];
				array_splice($msg_page_info, $msg_page);
				$this->requestStack->getSession()->set('msg_page_info',$msg_page_info);
			}

			$start_row = $page_s_rec;
			$sql .= " OFFSET {$start_row} ROWS";
			$sql .= " FETCH NEXT {$pageMax} ROWS ONLY;";

			$row = $this->dbs->fetchAllAssociative($sql,$paramVal);
			if ($row == false) {
				$row = [];
			}
		}

		// ログインユーザー 権限取得
//2025-08-21削除
//    	$edit_role = $this->ptm->gfn_login_user_role();
		// コメント用 返信ボタン/リアクションボタン 使用可能判定
		// メッセージ権限チェック 
		$msg_role = -1;
		$is_comment_post = "0";
		$is_comment_reaction = "0";
//2024-11-11 変更
		if (count($row) > 0) {
//2025-08-21変更(引数:[$table_msg_user]を追加)削除
//			$msg_role = $this->fn_msg_role_get($row[0]["msg_no"],$row[0]["msg_from_user_id"]);
//2025-08-28(復活)
			$msg_role = $this->fn_msg_role_get($row[0]["msg_no"],$row[0]["msg_from_user_id"],"",$table_msg_user);

//2025-08-21変更(無条件に許可)
//			if ( ($msg_role >= 2)
//			 	|| ($row[0]["is_comment_allow"] == 1 && $msg_role > 0)) {
//2025-08-28変更(本文の権限が◎の人は本文の「 コメント許可の」設定に関係なく、本文もコメントレスも可能)
//2025-08-24変更 msgにコメント許可の時はコメント可能
//			if ($row[0]["is_comment_allow"] == 1) {
			if ($msg_role >= 2 || $row[0]["is_comment_allow"] == 1) {
				$is_comment_post = "1";
				$is_comment_reaction = "1";
			}
		}

		// リアクションリスト追加
		$reaction_list = [];
//2025-01-10削除
		$read_count = count($row);
		$rec_count = ($read_count > $pageMax) ? $pageMax : $read_count;

//2024-12-29追加
		//親毎に子件数を求める
		$chain_key = "";
		$child_cnt = 0;
		$unread_cnt = 0;
		$chain_array = [];
		for ($i = 0;$i < $rec_count;$i++) {
			if ($chain_key != substr($row[$i]["chain_key"], 0, 3)) {
				$chain_key = substr($row[$i]["chain_key"], 0, 3);
				$child_cnt = 0;
				$unread_cnt = 0;
			}

			$child_cnt++;
			if ($child_cnt > 3 && $row[$i]["readclass"] === "unread") {
				$unread_cnt++;
			}

			if ($i == ($rec_count-1)) {
				$chain_array[$chain_key] = [ "child_cnt" => $child_cnt ];
				$chain_array[$chain_key] += [ "unread_cnt" => $unread_cnt ];
			} elseif($chain_key != substr($row[$i+1]["chain_key"], 0, 3))  {
				$chain_array[$chain_key] = [ "child_cnt" => $child_cnt ];
				$chain_array[$chain_key] += [ "unread_cnt" => $unread_cnt ];
			}
		}

		$child_num = 0;

//2025-08-011修正
		// 添付ファイル処理
		//***************************************************************************************
		// 添付ファイル初期設定
		$attached_file_dir = "../../files/attached/msg";
		// メッセージNOの設定
		$folder_msg_no = sprintf('%08d', $msg_no);
		$attached_file_dir .= "/{$folder_msg_no}";
//		$file_dir = $attached_file_dir;
//コメント繰返し処理
		for ($i = 0;$i < $rec_count;$i++) {

//2024-12-29追加
			if ($chain_key != substr($row[$i]["chain_key"], 0, 3)) {
				$chain_key = substr($row[$i]["chain_key"], 0, 3);
				$child_num = 0;
			}
			$child_num++;

			//メッセージ宛先リスト(参加者)
//			$row[$i]["data_member"] = $this->fn_msg_member_get($msg_no,$row[$i]["comment_no"],"comment");
			$key_para = ["new_record" => 0, "msg_no" => $msg_no, "comment_no" => $row[$i]["comment_no"] ];
//2025-08-21追加(パラメータ追加)
			$key_para += ["msg_user" => $table_msg_user ];
			$key_para += ["view_mode" => ($mode == "view" ? 1 : 0) ];
			$row[$i]["data_member"] = $this->pmm->fn_sankasha_member_get("comment",$key_para);
//2025-08-21変更(関数の変更)保留
//			$row[$i]["data_member"] = $this->pmm->fn_sankasha_comment_member_get($key_para,$table_msg_user);
			// メッセージ既読ユーザーリストの作成
//test中
//			$row[$i]["kidoku_list"] = $this->fn_read_list_get($msg_no,$row[$i]["comment_no"]);
//			$row[$i]["kidoku_list"] = []; //$this->fn_read_list_get($msg_no,$row[$i]["comment_no"]);
			// メッセージリアクションユーザーリストの作成
//2025-08-21変更(引数:[$table_msg_action]を追加)
//			$row[$i]["reation_list"] = $this->fn_reaction_list_get($msg_no,$row[$i]["comment_no"]);
			$row[$i]["reation_list"] = $this->fn_reaction_list_get($msg_no,$row[$i]["comment_no"],$table_msg_action);

			//作成.更新の曜日取得
//2025-05-05共通化
			$row[$i]["cre_week"] = $this->cmn->fn_get_date_week($row[$i]["from_at"]);
			$row[$i]["modi_week"] = $this->cmn->fn_get_date_week($row[$i]["modified_at"]);
			$row[$i]["update_week"] = $this->cmn->fn_get_date_week($row[$i]["update_at"]);

			//if ($mode == "edit" || $mode == "reply") {
				$row[$i]["attach"] = $this->atm->init($attached_file_dir,$row[$i]["comment_no"]);
			//}
		//***************************************************************************************
			$path_para = [ "mode"   => "view"
						  ,"msg_no" => $msg_no
						];
			$url = $this->router->generate("app_msg_detail",$path_para);
//2024-05-09変更
			$url = $this->ptm->fn_url_get($url);
			$row[$i]["url"] = $url;
			//権限の取得
//2024-11-28変更
			//$row[$i]["role"] = $this->fn_msg_role_get($row[$i]["msg_no"],$row[$i]["from_user_id"]);
//2025-08-21変更(引数:[$table_msg_user]を追加)削除
//			$row[$i]["role"] = $this->fn_msg_role_get($row[$i]["msg_no"],$row[$i]["from_user_id"],$row[$i]["comment_no"]);
//			$row[$i]["role"] = $this->fn_msg_role_get($row[$i]["msg_no"],$row[$i]["from_user_id"],$row[$i]["comment_no"],$table_msg_user);
			
			$is_comment_edit = "0";
//2025-08-21変更
//			if (($row[$i]["myclass"] == "my" || $row[$i]["role"] == 2)) {
			if ($row[$i]["myclass"] == "my") {
				$is_comment_edit = "1";
			}
			$row[$i]["is_comment_edit"] = $is_comment_edit;
			
			//日付,ログインID,kind=user
			$ndata = date("Ymd").":".$login_user_id.":"."user";
//			if ($row[$i]["link_yotei_no"] == "") {
			if (is_null($row[$i]["link_yotei_no"])) {
//2025-05-12変更
//				$link_yotei_path = $this->router->generate('app_schedule_detail'
//				,['mode' => "snew",'sche_no'=> "0",'ndata'=> $ndata,'caller'=> "-" ]);
	 			$link_yotei_path = $this->router->generate('app_schedule'
	 					,["para"     => "none"
	 					,"group"     => "-"
	 					,"kind"      => "mygroup"
	 					,"cal_style" => "gw"
	 					,"fname"     => "-"
	 					,"row_kind"  => "-"
	 					,"row_id"    => $msg_no
	 					,"nav"       => "+"
	 					]);
			} else {
//2024-10-27変更(権限を判定する様に追加)
				if ($this->cmn->fn_sche_role_get($row[$i]["link_yotei_no"],$login_user_id) !== "" ){
					$link_yotei_path = $this->router->generate('app_schedule_detail'
					,['mode' => "sview",'sche_no'=> $row[$i]["link_yotei_no"],'ndata'=> $ndata,'caller'=> "-" ]);
				} else {
					$link_yotei_path = "";
				}
			}
			$row[$i]["link_yotei_path"] = $link_yotei_path;
			if (empty($row[$i]["msg_link_no"])) {
				$link_msg_path = $this->router->generate('app_msg_detail'
				,['mode' => "edit",'msg_no'=> "0" ]);
			} else {
				$link_msg_path = $this->router->generate('app_msg_detail'
//2025-06-23変更
//				,['mode' => "view",'msg_no'=> $row[$i]["msg_link_no"] ]);
				,['mode' => "view2",'msg_no'=> $row[$i]["msg_link_no"] ]);
			}
			$row[$i]["link_msg_path"] = $link_msg_path;
//2025-05-08追加
			$link_anken_path = "";
			if (!empty($row[$i]["anken_link_no"])) {
				$link_anken_path = $this->router->generate('app_anken_view'
				,['anken_no' => $row[$i]["anken_link_no"],'caller'=> "-",'tab'=> "1" ]);
			}
			$row[$i]["link_anken_path"] = $link_anken_path;

	//リンクコピー用URL
			$key_no = $row[$i]["msg_no"] . "," . $row[$i]["comment_no"];
			$link_url = $this->router->generate('app_global_link'
										,['kind' => 'c', 'key_no' => $key_no ]);
			$row[$i]["link_url"] = $link_url;

//2025-01-01以下チェック
//2024-12-29追加
			$row[$i]["child_num"] = $child_num;
			$row[$i]["child_cnt"] = $chain_array[$chain_key]["child_cnt"];
			$row[$i]["unread_cnt"] = $chain_array[$chain_key]["unread_cnt"];
		}

		$para = [];
		$para["caller"] = "comment";

//2025-08-21変更(viewは対象外)
//		if ($comment_no != "" || $mode == "reply") {
		if (($mode != "view") && ($comment_no != "" || $mode == "reply")) {
			//メッセージデータ、宛先フォーム作成
//2024-12-19削除
//			$para["share_form"] = $this->twig->render("parts/mdl_role_edit.twig",["mode" => "comment"]);

			$combo_data = [];
			
//2024-11-26追加
			//組織グループ
	$sql = "
SELECT	a.msg_no
	, soshiki_group_id = b.user_id
	, d.soshiki_group_name
FROM	dbo.t_msg a
	INNER JOIN  dbo.t_msg_user b
		ON b.msg_no = a.msg_no
		and b.user_type = 1
		and b.comment_no  IS NULL
	INNER JOIN dbo.m_soshiki_group d 
		ON d.soshiki_group_id = b.user_id
WHERE (a.msg_no = :msg_no)
  AND (b.user_id <> :user_id2)
  AND (b.user_role >= :user_role)

";

			$paramVal  = ["user_id2"  => "su" ];
			$paramVal += ["msg_no"    => $msg_no ];
			$paramVal += ["user_role" => 1 ];
			$rst = $this->dbs->fetchAllAssociative($sql,$paramVal);

			$rec_cnt = count($rst);
			$record = [];
			for ($i = 0;$i < count($rst);$i++) {
				$record[] = [ "id" => $rst[$i]['soshiki_group_id'],"value" => $rst[$i]['soshiki_group_name'] ];
			}
			$combo_data["soshiki_group"] = json_encode($record);
			//[Myグループ]
			$data = $this->cbms->combo_create("mygroup");
			// データリスト
			$combo_data["mygroup"] = json_encode($data["record"]);
			$para["combo_data"] = $combo_data;

//2024-11-10追加
			if ($mode == "reply") {
//				$msg_sankasha_list = $this->ptm->fn_sankasha_list_get("msg",$msg_no,$comment_no ,"");
//2025-05-15変更
//				$msg_sankasha_list = $this->ptm->fn_sankasha_list_get("comment",$msg_no,$comment_no ,"");
				$msg_sankasha_list = $this->pmm->fn_sankasha_list_get("comment",$msg_no,$comment_no ,"");
				$row[0]["data_member"] = $msg_sankasha_list;
			}

//2025-08-11追加
			$row[0]["memo"] = $this->atm->fn_memo_temp_path_set("msg", $row[0]["memo"], $msg_no, $comment_no);
			$memo_path_dir = "./images/msg/tmp/{$login_user_id}/";;
			$row[0]["memo_path"] = $memo_path_dir;
		}
		//ボタン表示用
		$btn_row = [];
		$btn_row["comment_no"] = $comment_no;
		$btn_row["mode"] = $mode;
//2025-08-21削除
//		$btn_row["edit_role"] = $edit_role;
		$btn_row["is_comment_post"] = $is_comment_post;
		$btn_row["is_comment_reaction"] = $is_comment_reaction;

		if ($read_count != $rec_count) {
			unset($row[$rec_count]);
		}
		
		if ($comment_no != "" || $mode == "reply") {
			$rst = $row[0];
		} else {
			$rst = $row;
		}
//2025-01-04追加
		$para += ['comment_order'       => $comment_order ];

		//ページ制御
		$frm  = ["page_num" => $msg_page ];
		$frm += ["msg_no"   => $msg_no ];
//2025-01-10変更
//		$paginate = $this->fn_msg_page_control($frm,$read_count,$pageMax);
		$paginate = $this->fn_msg_page_control($frm,$pageMax,$next_f);

//2025-08-21追加
		//テンポラリーテーブル削除
		$this->fn_temp_table_delete($temp_table);

		return [ "status"              => true
				,"comment"             => $rst
				,"comment_para"        => $para
				,"comment_btn"         => $btn_row
				,'comment_paginate'    => $paginate
//2024-12-28追加
				,'comment_order'       => $comment_order
				,'order_post'          => ($comment_order == 0) ? "checked" : ""
				,'order_reply'         => ($comment_order == 1) ? "checked" : ""
//2025-03-19追加
				,'all_summary_f'       => $comment_all_summary_f
			   ];

	}

//2025-06-19追加
	//テンポラリーテーブル作成
	public function temp_create($msg_no) {
		$sql  = "select msg_no";
		$sql .= " from [t_msg_comment]";
		$sql .= " where [link_msg_no] =:msg_no";
		$paramVal = ["msg_no" => $msg_no ];
		$wrk = $this->dbs->fetchAssociative($sql,$paramVal);
		$comment_link_msg_no= "";
		if ($wrk != false) {
			$comment_link_msg_no = $wrk["msg_no"];
		}
		$this->temp_msg = "##temp_msg" . uniqid();
		$sql  = "IF EXISTS (select name from tempdb..sysobjects";
		$sql .= " WHERE id = OBJECT_ID('tempdb..".$this->temp_msg."') and type = 'U')";
		$sql .= " drop table [{$this->temp_msg}];";
		$sql .= "select *";
		$sql .= " into [{$this->temp_msg}]";
		$sql .= " from [t_msg]";
		$sql .= " where [msg_no] =:msg_no";
		if ($comment_link_msg_no != "") {
			$sql .= " or [msg_no] =:comment_link_msg_no";
		}
		$paramVal = ["msg_no" => $msg_no ];
		if ($comment_link_msg_no != "") {
			$paramVal += ["comment_link_msg_no" => $comment_link_msg_no ];
		}
		$ret = $this->dbs->executeStatement($sql,$paramVal);

		$this->temp_msg_comment = "##temp_msg_comment" . uniqid();
		$sql  = "IF EXISTS (select name from tempdb..sysobjects";
		$sql .= " WHERE id = OBJECT_ID('tempdb..".$this->temp_msg_comment."') and type = 'U')";
		$sql .= " drop table [{$this->temp_msg_comment}];";
		$sql .= "select *";
		$sql .= " into [{$this->temp_msg_comment}]";
		$sql .= " from [t_msg_comment]";
		$sql .= " where [msg_no] =:msg_no";
		$sql .= "    or [link_msg_no] =:link_msg_no";
		$paramVal  = ["msg_no"      => $msg_no ];
		$paramVal += ["link_msg_no" => $msg_no ];
		$ret = $this->dbs->executeStatement($sql,$paramVal);

		return false;
	}
	
	//コメント詳細 更新処理
	public function msg_comment_detail_update($frm,$member_list) {

		$msg_no = $frm["msg_no"];
		$comment_no = $frm["comment_no"];
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
		$table = "t_msg_comment";
		$update_time = new \DateTime();

		//存在チェック
		$eof_flg = false;
		if ($comment_no != 0) {
			//訂正の場合は、存在チェック
			$sql  = "select *";
			$sql .= " from [".$table."]";
			$sql .= " where [comment_no] = :comment_no";
			$paramVal = ["comment_no" => $comment_no ];
//2024-12-23追加
			$sql .= " and [msg_no] = :msg_no";
			$paramVal += ["msg_no"    => $msg_no ];
			
			$rst = $this->dbs->fetchAssociative($sql,$paramVal);
			if ($rst == false) {
				$eof_flg = true;
			}
		} else {
			$eof_flg = true;
		}

		//データセット
		$dataVal  = [];
//2025-08-01下に移動
//		$dataVal += ['memo'        			=> $this->cmn->fn_nz($frm["txt_memo"],null) ];

//2025-08-10追加(本文検索用テキスト)
//2025-08-01下に移動
//		$dataVal += ['s_memo'        		=> strip_tags($this->cmn->fn_nz($frm["txt_memo"],null)) ];

//2024-12-23新規の時のみ更新
//		$dataVal += ['from_user_id'     	=> $this->cmn->fn_nz($frm["txt_from_user_id"],null) ];
//		$dataVal += ['from_by'          	=> $this->cmn->fn_nz($frm["txt_from_by"],null) ];
//		$dataVal += ['from_at'        		=> $update_time->format('Y-m-d H:i:s') ];

		$dataVal += ['attached_file'    	=> $file_name ];
		$dataVal += ['msg_no'    	        => $msg_no ];

//		$dataVal += ["link_msg_no"			=> 0 ];
//		$dataVal += ["link_yotei_no"		=> 0 ];
		$dataVal += ["is_delete"			=> 0 ];

		if ($eof_flg) {
//2024-12-23新規の時のみ更新
			$dataVal += ['from_user_id'     	=> $this->cmn->fn_nz($frm["txt_from_user_id"],null) ];
			$dataVal += ['from_by'          	=> $this->cmn->fn_nz($frm["txt_from_by"],null) ];
			$dataVal += ['from_at'        		=> $update_time->format('Y-m-d H:i:s') ];

//2025-03-18変更(秒の出力を追加)
//			$dataVal += ['created_at'       => $update_time->format('Y-m-d H:i') ];
			$dataVal += ['created_at'       => $update_time->format('Y-m-d H:i:s') ];
			$dataVal += ['created_user_id'  => $user_id ];
			$dataVal += ['modified_at'      => null ];
			$dataVal += ['modified_user_id' => null ];
		} else {
//2025-03-18変更(秒の出力を追加)
//			$dataVal += ['modified_at'      => $update_time->format('Y-m-d H:i') ];
			$dataVal += ['modified_at'      => $update_time->format('Y-m-d H:i:s') ];
			$dataVal += ['modified_user_id' => $user_id ];
		}
		
		if ($eof_flg) {
			//トランザクション(開始)
			$rst = $this->dbs->beginTransaction();

			//comment_noの最大を再取得
			$sql  = "select isnull(max(comment_no),0)+1 as new_comment_no";
			$sql .= " from {$table}";
			$sql .= " where msg_no = :msg_no";
			$paramVal = ["msg_no" => $msg_no ];
			$rst_new = $this->dbs->fetchAssociative($sql,$paramVal);

			$sql  = "select chain_key";
			$sql .= " from {$table}";
			$sql .= " where msg_no = :msg_no";
			$sql .= "   and comment_no = :comment_no";
			$paramVal  = ["msg_no"     => $msg_no ];
			$paramVal += ["comment_no" => $frm['reply_no'] ];
			$rst_chain = $this->dbs->fetchAssociative($sql,$paramVal);
			if ($rst_chain == false) {
				$chain_key = "";
			} else {
				$chain_key = $rst_chain["chain_key"];
			}
			$chain_key .= sprintf("%03d/",$rst_new["new_comment_no"]);

			//新規の場合は、追加
			$dataVal += ["comment_no"  => $rst_new["new_comment_no"] ];
			$dataVal += ["reply_no"    => $frm["reply_no"] ];
			$dataVal += ["chain_key"   => $chain_key ];

			$comment_no = $rst_new["new_comment_no"];

//2025-08-11追加
			//本文「添付ファイル」パスを保存用に変更
			$edit_memo = $this->atm->fn_memo_save_path_set($frm["txt_memo"],$msg_no,$comment_no);
			$dataVal += ['memo'        => $edit_memo ];
			$dataVal += ['s_memo'      => strip_tags($edit_memo) ];

			//追加実行
			$rst = $this->dbs->insert($table,$dataVal);

			//トランザクション(終了)
			$rst = $this->dbs->commit();
		} else {
//2025-08-11追加
			$edit_memo = $this->atm->fn_memo_save_path_set($frm["txt_memo"],$msg_no,$comment_no);

			$dataVal += ['memo'        => $edit_memo ];
			$dataVal += ['s_memo'      => strip_tags($edit_memo) ];

			//更新実行
			//パラメータ設定
			$paramKey = ["id" => intval($frm['id'])];

			//更新実行
			$ret = $this->dbs->update($table,$dataVal,$paramKey);
		}

		//***************************************
		//メッセージ詳細(ユーザー)登録
		//***************************************
		$table_user = "t_msg_user";

		$sql = "delete ";
		$sql .= " from {$table_user}";
		$sql .= " Where msg_no = :msg_no";
		$sql .= "   and comment_no = :comment_no";
		$paramVal  = ["msg_no"     => $msg_no ];
		$paramVal += ["comment_no" => $comment_no ];
		$ret = $this->dbs->executeUpdate($sql,$paramVal);

		if (isset($member_list["sankasha"])) {
			$sankasha_list = $member_list["sankasha"];
			if ($sankasha_list != "") {
				for ($i = 0;$i < count($sankasha_list);$i++) {
					$dataVal  = ["msg_no"     => $msg_no ];
					$dataVal += ["comment_no" => $comment_no ];
					$dataVal += ["user_type"  => $sankasha_list[$i]['user_type'] ];
					$dataVal += ["user_id" 	  => $sankasha_list[$i]['user_id'] ];
					$dataVal += ["user_role"  => $sankasha_list[$i]['user_role'] ];
					//追加実行
					$ret = $this->dbs->insert($table_user,$dataVal);				
				}
			}
		}

		//***************************************
		//メッセージ詳細(アクション)更新
		//登録済み 同一メッセージNO/コメントNO を未読に更新
		//***************************************
		$table_action = "t_msg_action";

		$paramVal  = ['msg_no'     => $msg_no ];
		$paramVal += ['comment_no' => $comment_no ];
		$dataVal = ['is_read' => 0 ];

		$ret = $this->dbs->update($table_action,$dataVal,$paramVal);

// 2025-03-30追加(ログ出力) -----------
		$para_l = [];
		if ($eof_flg) {
			$para_l["action"] = "新規";
		} else {
			$para_l["action"] = "更新";
		}
		$para_l["msg_no"] = $msg_no;
		$para_l["comment_no"] = $comment_no;
		$this->fn_log_out($para_l);
//-------------------------------------

//2025-03-05追加
		//コメント情報作成
		$this->fn_comment_read_create($msg_no,$comment_no);

		// 添付ファイル保存処理
		// 保存フォルダの再設定
		$attached_file_dir = "../../files/attached/msg";
		$folder_msg_no = sprintf('%08d', $msg_no);
		$attached_file_dir .= "/{$folder_msg_no}";
		// コメントNOの設定
		$folder_comment_no = sprintf('%04d', $comment_no);
		$attached_file_dir .= "/{$folder_comment_no}";

		$this->atm->fileSave($frm['tmp_dir'],$attached_file_dir);

//2025-08-11追加
		//本文「添付ファイル」の保存
		$this->atm->fn_memo_file_save("msg", $edit_memo, $msg_no, $comment_no);

		//メッセージ再取得
		$data = $this->comment_detail_get("view",$msg_no);

		return ["status" => true ,"data" => $data, "comment_no" => $comment_no ];

	}

	//コメント情報作成
	private function fn_comment_read_create($msg_no,$comment_no) {

		//テンポラリーテーブル初期設定
		$temp_action = "#temp_action";
		$sql  = "IF EXISTS (select name from tempdb..sysobjects";
		$sql  .= " WHERE id = OBJECT_ID('tempdb..".$temp_action."') and type = 'U')";
		$sql  .= " drop table [{$temp_action}];";
		
		//テンポラリーテーブル作成
		$sql1 = "
select
	a.msg_no
	,a.comment_no
	,a.user_id
into {$temp_action}
from (
select s1.msg_no
		,s1.comment_no
		,user_id =  s12.soshiki_user_id
from t_msg_comment s1
	inner join t_msg_user s11
		on  s11.msg_no = s1.msg_no
		and s11.comment_no = s1.comment_no
	inner join m_soshiki_user s12
		on s12.soshiki_group_id = s11.user_id
where s1.msg_no = :msg_no1
  and s1.comment_no = :comment_no1
  and s11.user_type=1

union all 

select s2.msg_no
		,s2.comment_no
		,s21.user_id
from t_msg_comment s2
	left join t_msg_user s21
		on  s21.msg_no = s2.msg_no
		and s21.comment_no = s2.comment_no
where s2.msg_no = :msg_no2
  and s2.comment_no = :comment_no2
  and s21.user_type=2) a
group by a.msg_no,a.comment_no,a.user_id;";

		$paramVal  = ['msg_no1' => $msg_no ];
		$paramVal += ['msg_no2' => $msg_no ];
		$paramVal += ['comment_no1' => $comment_no ];
		$paramVal += ['comment_no2' => $comment_no ];

		//既存テーブルより削除データのクリア
		$sql2  = "
delete a
from t_msg_action a
  left join {$temp_action} b
     on  b.msg_no = a.msg_no
     and b.comment_no = a.comment_no
     and b.user_id = a.user_id
where a.msg_no = :msg_no3
  and a.comment_no = :comment_no3
  and b.user_id is null;";
		$paramVal += ['msg_no3' => $msg_no ];
		$paramVal += ['comment_no3' => $comment_no ];

		//既存テーブルに存在しないデータの追加
		$sql3  = "
insert into t_msg_action
 (user_id,msg_no,comment_no,is_read)
select a.user_id
      ,a.msg_no
      ,a.comment_no
      ,is_read = 0
from {$temp_action} a
  left join t_msg_action b
     on  b.msg_no = a.msg_no
     and b.user_id = a.user_id
     and b.comment_no = a.comment_no
where b.user_id is null;";

		$sql .= $sql1.$sql2.$sql3; 
		$ret = $this->dbs->executeUpdate($sql,$paramVal);

		return false;

	}

	//コメント復元
	public function msg_detail_comment_restore($msg_no,$comment_no) {

		$table = "t_msg_comment";

		$update_time = new \DateTime();
		$update_time = $update_time->format('Y-m-d H:i:s');
		$user_id = $this->user->getUserId();

		//項目設定
		$dataVal  = ["is_delete"        => 0];
		$dataVal += ["modified_user_id" => $user_id ];
		$dataVal += ["modified_at"      => $update_time ];

		//更新実行
		//パラメータ設定
		$paramKey  = ["msg_no"      => $msg_no ];
		$paramKey += ["comment_no"  => $comment_no ];

		//更新実行
		$ret = $this->dbs->update($table,$dataVal,$paramKey);

// 2025-03-30追加(ログ出力) -----------
		$para_l = [];
		$para_l["action"] = "復元";
		$para_l["msg_no"] = $msg_no;
		$para_l["comment_no"] = $comment_no;
		$this->fn_log_out($para_l);
//-------------------------------------

		//メッセージ再取得
		$data = $this->comment_detail_get("view",$msg_no);
		
		return $data;
	}



	//メッセージ_ユーザー情報 更新
	public function msg_user_update($msg_no,$comment_no,$reaction_no) {

		$table = "t_msg_action";
		$user_id = $this->user->getUserId();

		$sql  = "select [id] = isnull(b.id,0)";
		$sql .= " ,isnull(b.user_id,'') As user_id";
		$sql .= " ,isnull(b.reaction,'') As reaction";
		if ($comment_no == 0) {
			// メッセージデータの場合
			$sql .= ",[is_draft] = isnull(a.is_draft,0)";
			$sql .= " from t_msg a";
			$sql .= "    left join t_msg_action b";
//2025-04-12変更
//			$sql .= "      on  b.user_id = :user_id";
			$sql .= "      on  b.msg_no = a.msg_no";
			$sql .= "      and b.comment_no is null";
		} else {
			// コメントデータの場合
			$sql .= ",[is_draft] = 0";
			$sql .= " from t_msg_comment a";
			$sql .= "    left join t_msg_action b";
//2025-04-12変更
//			$sql .= "      on  b.user_id = :user_id";
			$sql .= "      on  b.msg_no = a.msg_no";
			$sql .= "      and b.comment_no = :comment_no";
		}
		$sql .= " where a.msg_no = :msg_no";
//2025-04-12追加
		$sql .= "   and b.user_id = :user_id";
		if ($comment_no != 0) {
			$sql .= "   and a.comment_no = :comment_no2";
		}
		$paramVal  = ["msg_no"     => $msg_no ];
		$paramVal += ["user_id"    => $user_id ];
		$paramVal += ["comment_no" => $comment_no ];
		$paramVal += ["user_id2"   => $user_id ];
		if ($comment_no != 0) {
			$paramVal += ["comment_no2" => $comment_no ];
		}
		$rst = $this->dbs->fetchAssociative($sql,$paramVal);
		if ($rst != false) {
			//項目設定
			if ($rst["is_draft"] == 0) {
				if ($rst["user_id"] == "") {
					$dataVal  = ["user_id"     => $user_id ];
					$dataVal += ["msg_no"      => $msg_no ];
					if ($comment_no == 0) {
						$dataVal += ["comment_no"  => null ];
					} else{
						$dataVal += ["comment_no"  => $comment_no ];
					}
					$dataVal += ["reaction"    => $reaction_no ];
					$dataVal += ["is_read"     => 1 ];

					//追加実行
//2025-03-06debug delete
					//$ret = $this->dbs->insert($table,$dataVal);
				} else {
					if ($rst["reaction"] == $reaction_no) {
						// 登録済みリアクションと同じ場合は、取消とする
						$dataVal = ["reaction"  => 0 ];				// リアクション取消
					} else {
						$dataVal = ["reaction"  => $reaction_no ];	// リアクション設定
					}
					
					$dataVal += ["is_read"     => 1 ];
					//更新実行
					//パラメータ設定
					$paramKey = [ "id" => $rst['id'] ];

					//更新実行
					$ret = $this->dbs->update($table,$dataVal,$paramKey);
				}
			}
		} else {
//2025-04-08追加
			$dataVal  = ["user_id"     => $user_id ];
			$dataVal += ["msg_no"      => $msg_no ];
			if ($comment_no != 0) {
				$dataVal += ["comment_no"  => $comment_no ];
			} else {
				$dataVal += ["comment_no"  => null ];
			}
			$dataVal += ["reaction"    => 0 ];
			$dataVal += ["is_read"     => 1 ];
			$dataVal += ["is_pin"      => null ];

			//追加実行
			$ret = $this->dbs->insert($table,$dataVal);
		}
		
		//リード人数 集計
		$read_su = 0;
		if ($comment_no == 0 && $reaction_no == 0) {
			$sql  = "select count(a.id) as read_su";
			$sql .= " from {$table} a";
			$sql .= "  where a.msg_no = :msg_no";
			$sql .= "    and a.comment_no is null";
			$sql .= "    and a.is_read = :is_read";
			$paramVal  = ["msg_no"     => $msg_no ];
			$paramVal += ["is_read"    => 1 ];
			$rst = $this->dbs->fetchAssociative($sql,$paramVal);
			if ($rst != []) {
				$read_su = $rst["read_su"];
			}
		}

		//メッセージ_ユーザー情報 取得
		$reaction_su = [];
		$reaction_user = [];
		if ($reaction_no != 0) {
//2025-01-14変更
			$reaction_data = $this->msg_user_action_get($msg_no,$comment_no);
			$reaction_su = $reaction_data["reaction_su"];
		   	$reaction_user = $reaction_data["reaction_user"];
		}
		return [ "status"        => true
			   , "read_su"       => $read_su
			   , "reaction_su"   => $reaction_su
			   , "reaction_user" => $reaction_user
			   ];

	}

//2025-08-21変更(引数:[$table_msg_action]を追加)
//2025-01-14追加
	//メッセージ_ユーザー情報 取得
	public function msg_user_action_get($msg_no,$comment_no,$table_msg_action="") {

//2025-08-21変更
//		$table = "t_msg_action";
		if ($table_msg_action == "") {
			$table = "t_msg_action";
		} else {
			$table = $table_msg_action;
		}
		
		$user_id = $this->user->getUserId();

		//リアクション人数 集計
		$reaction_su = [];
		$reaction_user = [];
		// 初期値セット
		for($i = 0;$i < 6;$i++) {
			$reaction_su[] = 0;
			$reaction_user[] = [];
		}
		$sql  = "select reaction,count(id) as reaction_su";
		$sql .= " from {$table}";
		$sql .= " where msg_no = :msg_no";
		if ($comment_no == 0) {
			$sql .= "   and comment_no is null"; 
		} else{
			$sql .= "   and comment_no = :comment_no"; 
		}
		$sql .= "   and reaction <> 0";
		$sql .= " group by reaction";
		$paramVal  = ["msg_no"     => $msg_no ];
		if ($comment_no != 0) {
			$paramVal += ["comment_no" => $comment_no ];
		}
		$rst = $this->dbs->fetchAllAssociative($sql,$paramVal);
		for($i = 0;$i < count($rst);$i++) {
			$reaction_su[ $rst[$i]["reaction"] ] = $rst[$i]["reaction_su"];
		}

		// 並び順:リアクションNO,ユーザー名
		$sql  = "select [user_name] = b.[ユーザー名]";
		$sql .= ",a.reaction";
		$sql .= " from {$table} a";
		$sql .= "    inner join [M_User] b";
		$sql .= "      on b.[ユーザーID] = a.user_id";
//2025-01-14追加
		$sql .= "    inner join [m_soshiki_user] d";
		$sql .= "       on  d.[soshiki_user_id] = a.[user_id]";
		$sql .= "       and d.[soshiki_group_id] = :soshiki_group_id";
		$sql .= " where a.msg_no = :msg_no";
		if ($comment_no == 0) {
			$sql .= "   and a.comment_no is null"; 
		} else{
			$sql .= "   and a.comment_no = :comment_no"; 
		}
		$sql .= "   and a.reaction > :reaction";
//2025-01-14変更
//			$sql .= " order by a.reaction,b.[ユーザー名]";
		$sql .= " order by a.reaction,d.[disp_order]";
		$paramVal  = ["msg_no"   => $msg_no ];
		if ($comment_no != 0) {
			$paramVal += ["comment_no" => $comment_no ];
		}
		$paramVal += ["reaction" => 0 ];
//2025-01-14追加
		$paramVal += ["soshiki_group_id" => '10' ];
		$rst = $this->dbs->fetchAllAssociative($sql,$paramVal);
		for ($i =0;$i < count($rst);$i++){
			$reaction_user[ $rst[$i]["reaction"] ][] = $rst[$i]["user_name"];
		}

		return [ "status"        => true
			   , "reaction_su"   => $reaction_su
			   , "reaction_user" => $reaction_user
			   ];

	}
//2025-08-21変更(引数:[$table_msg_user]を追加)
//2024-11-28変更(引数:[$comment_no]を追加)
	//メッセージ権限取得
//    private function fn_msg_role_get($msg_no,$from_user_id,$comment_no="") {
    public function fn_msg_role_get($msg_no,$from_user_id,$comment_no="",$table_msg_user="") {
	//"":設定なし 0:閲覧 1:メンバー 2:全権 9:作成者

		$login_user_id = $this->user->getUserId();

		//スーパーユーザーチェック
		//$ret = $this->cmn->fn_su_role_chk($login_user_id);
		//if ($ret == "su") {
		//	$role = 2;
		//	return $role;
		//}

//2025-08-21追加
		if ($table_msg_user == "") {
			$table = "t_msg_user";
		} else {
			$table = $table_msg_user;
		}

		$role = "";

		//ログインユーザーIDが作成者の場合、作成者
		if ($from_user_id == $login_user_id) {
			$role = 9;
			return $role;
		}

		//[メッセージユーザー]設定テーブルより、ログインユーザーの権限取得

		//[グループ]設定データの取得
//2024-12-27変更
//		$sql  = "select b.[user_id]";
//		$sql .= ",[user_role] = max(a.[user_role])";
//		$sql .= " from [t_msg_user] a";
//		$sql .= "    inner join [m_user_soshiki] b";
//		$sql .= "       on  b.[soshiki_group_id] = a.[user_id]";
//		$sql .= "       and b.[user_id] = :user_id";
		$sql  = "select [user_id] = b.[soshiki_user_id]";
		$sql .= ",[user_role] = max(a.[user_role])";
//2025-08-21変更
//		$sql .= " from [t_msg_user] a";
		$sql .= " from [{$table}] a";
//2025-02-24変更
//		$sql .= "    inner join [m_user_soshiki] b";
		$sql .= "    inner join [m_soshiki_user] b";
		$sql .= "       on  b.[soshiki_group_id] = a.[user_id]";
		$sql .= "       and b.[soshiki_user_id] = :user_id";
		
		//[グループ]設定データの取得
//2024-12-27変更
//		$sql  = "select b.[user_id]";
		$sql  = "select [user_id] = b.[soshiki_user_id]";
		$sql .= ",[user_role] = max(a.[user_role])";
//2025-08-21変更
//		$sql .= " from [t_msg_user] a";
		$sql .= " from [{$table}] a";
//2024-12-27変更
//		$sql .= "    inner join [m_user_soshiki] b";
//		$sql .= "       on  b.[soshiki_group_id] = a.[user_id]";
//		$sql .= "       and b.[user_id] = :user_id";
		$sql .= "    inner join [m_soshiki_user] b";
		$sql .= "       on  b.[soshiki_group_id] = a.[user_id]";
		$sql .= "       and b.[soshiki_user_id] = :user_id";
		
		$sql .= " where a.[msg_no]  = :msg_no";
//2024-11-28変更
//2024-11-11 comment_no追加
		//$sql .= "   and a.[comment_no] is null";
		if ($comment_no == "") {
			$sql .= "   and a.[comment_no] is null";
		} else {
			$sql .= "   and a.[comment_no] = :comment_no";
		}
		$sql .= "   and a.[user_type] = :user_type";
//2024-12-27変更
//		$sql .= " group by b.[user_id]";
		$sql .= " group by b.[soshiki_user_id]";

		$paramVal  = ["msg_no"		=> $msg_no ];
		$paramVal += ["user_type"	=> 1 ];
		$paramVal += ["user_id"  	=> $login_user_id ];
//2024-11-28追加
		if ($comment_no != "") {
			$paramVal += ["comment_no" => $comment_no ];
		}

		$rst = $this->dbs->fetchAssociative($sql,$paramVal);
		if ($rst != false) {
			$role = $rst['user_role'];
		}

		//[ユーザー]設定データの取得
		$sql  = "select [user_id]";
		$sql .= ",[user_role]";
//2025-08-21変更
//		$sql .= " from [t_msg_user]";
		$sql .= " from [{$table}]";
		$sql .= " where [msg_no]    = :msg_no";
		$sql .= "   and [user_type] = :user_type";
		$sql .= "   and [user_id]   = :user_id";
//2024-11-28変更
//2024-11-11 comment_no追加
		//$sql .= "   and [comment_no] is null";
		if ($comment_no == "") {
			$sql .= "   and [comment_no] is null";
		} else {
			$sql .= "   and [comment_no] = :comment_no";
		}
		$paramVal  = ["msg_no"		=> $msg_no ];
		$paramVal += ["user_type"	=> 2 ];
		$paramVal += ["user_id"  	=> $login_user_id ];
//2024-11-28追加
		if ($comment_no != "") {
			$paramVal += ["comment_no" => $comment_no ];
		}
		$rst = $this->dbs->fetchAssociative($sql,$paramVal);
		if ($rst != false) {
			if ($rst['user_role'] > $role) {
				$role = $rst['user_role'];
			}
		}

		return $role;
		
	}

	//メッセージ既読ユーザーリストの作成　ユーザー名
//	private function fn_read_list_get($msg_no = "",$comment_no = "0") {
	public function fn_read_list_get($msg_no = "",$comment_no = "0") {
	
		//作成ユーザー以外を集計
		$sql  = "select c.[社員CD]";
		$sql .= ",is_read = max(a.is_read)";
		$sql .= ",user_name = min(c.[社員名])";
//2025-03-03追加(read状態)
		$sql .= ",[read_jotai] = (case when min(a.is_read) = 0 then '未開封' else '' end)";
		$sql .= " from t_msg_action a";
		$sql .= "    inner join [M_User] b";
		$sql .= "       on b.[ユーザーID] = a.[user_id]";
		$sql .= "    inner join [C_社員] c";
		$sql .= "       on c.[社員CD] = b.[社員CD]";
		$sql .= "    inner join t_msg d";
		$sql .= "      on d.msg_no = a.msg_no";
		$sql .= " where a.msg_no = :msg_no";
		if ($comment_no == 0) {
			$sql .= "   and a.comment_no is null";
		} else{
			$sql .= "   and a.comment_no = :comment_no";
		}

//2024-12-04追加
		$sql .= "   and (c.[退職フラグ] = :taishoku_f)";
		$sql .= "   and (c.[非表示区分] = :non_disp_f)";

		$sql .= " group by c.[社員CD]";
		$sql .= " order by c.[社員CD]";
		$paramVal  = ["msg_no"  => $msg_no ];
		if ($comment_no != 0) {
			$paramVal += ["comment_no" => $comment_no ];
		}

//2024-12-04追加
		$paramVal += ["taishoku_f" => 0 ];
		$paramVal += ["non_disp_f" => 0 ];

		$rst = $this->dbs->fetchAllAssociative($sql,$paramVal);
		$read_rst = [];
		$read_count = 0;
		if ($rst != false) {
			$read_rst = $rst;
//2025-03-12変更(Read済のカウントを集計する様に変更)
//			$read_count = count($rst);

			for ($i=0;$i<count($rst);$i++) {
				if ($rst[$i]["is_read"] == 1) {
					$read_count++;
				}
			}
		}

		return [ "read_rst" => $read_rst, "read_su" => $read_count ];
	}

//2025-08-21変更(引数:[$table_msg_action]を追加)
	//メッセージリアクションユーザーリストの作成　ユーザー名、リアクションNO
//	private function fn_reaction_list_get($msg_no = "",$comment_no = "0") {
	public function fn_reaction_list_get($msg_no = "",$comment_no = "0", $table_msg_action = "") {

//2025-08-21変更(引数:[$table_msg_action]を追加)
//2025-01-14変更
		//メッセージ_ユーザー情報 取得
		$reaction_data = $this->msg_user_action_get($msg_no,$comment_no,$table_msg_action);
		$reaction_list = $reaction_data["reaction_user"];

		return $reaction_list;
	}

	//メッセージ情報の取得
//2025-01-10変更
//	private function fn_msg_page_control($frm,$read_count){
	private function fn_msg_page_control($frm,$pageMax,$next_f){

		//ページ処理
		$page = $frm["page_num"];
		$msg_no = $frm["msg_no"];
		//ページ番号のクリア
		$first = 0;
		$previous = 0;
		$current = 0;
		$next = 0;

		if ($page > 1) {
			$first = 1;
			$previous = $page - 1;
		}

		$current = $page;
//2025-01-10変更
//		if (($read_count > $pageMax)) {
		if ($next_f == 1) {
			$next = $page + 1;
		}
		$paginate = ['first'   => ["page" => $first   ,"class" => (($first > 0) ? "" : "fontDisable") ]
					,'prev'    => ["page" => $previous,"class" => (($previous > 0) ? "" : "fontDisable") ]
					,'current' => ["page" => $current ,"class" => (($current > 0) ? "" : "fontDisable") ]
					,'next'    => ["page" => $next    ,"class" => (($next > 0) ? "" : "fontDisable") ]
					,'pageMax' => self::pageMax
					,'msg_no'  => $msg_no
					];
		return $paginate;

	}

	
	// 2025-03-30追加 アクセスログ出力
	private function fn_log_out($para_l) {

		$para  = [ "category"   => isset($para_l["comment_no"])  ? "comment"             : "msg" ];
		$para += [ "action"     => isset($para_l["action"])      ? $para_l["action"]     : null ];
		$para += [ "subject"    => isset($para_l["subject"])     ? $para_l["subject"]    : null ];
		$para += [ "msg_no"     => $para_l["msg_no"] ];
		$para += [ "comment_no" => isset($para_l["comment_no"])  ? $para_l["comment_no"] : null ];
		$this->lgm->access_log_out($para);

	}

//未使用Function
	//共有先の対象データ取得
	public function delete_roleFilterGet($mode,$para) {

		$list_data = [];

		switch ($mode) {
			case "group":
				//グループ抽出
				$sql  = "select [user_id] = [soshiki_group_id]";
				$sql .= " ,[user_name] = [soshiki_group_name]";
				$sql .= " ,[disp_order]";
				$sql .= " from [m_soshiki_group]";
				$sql .= " where [soshiki_group_id] = :soshiki_group_id";
				$paramVal = ["soshiki_group_id" => $para ];
				$rst = $this->dbs->fetchAssociative($sql,$paramVal);
				if ($rst != false) {
					$list_data[] = [ "type"  => 1					//1:グループ
									,"code"  => $rst["user_id"]
									,"name"  => $rst["user_name"]
									,"disp_order"  => intval($rst["disp_order"])
									];
				}
				
				//ユーザー抽出準備
				$sql  = "select [user_id] = a.[soshiki_user_id]";
				$sql .= " ,[user_name] = c.[社員名]";
				
				$sql .= " from [m_soshiki_user] a";
				$sql .= "    inner join [M_User] b";
				$sql .= "       on b.[ユーザーID] = a.[soshiki_user_id]";

				$sql .= "    inner join [C_社員] c";
				$sql .= "       on c.[社員CD] = b.[社員CD]";
				$sql .= " where a.[soshiki_group_id] = :soshiki_group_id";

//2024-12-04追加
				$sql .= " and (c.[退職フラグ] = :taishoku_f)";
				$sql .= " and (c.[非表示区分] = :non_disp_f)";

				$sql .= " order by a.[user_id]";
				$paramVal = ["soshiki_group_id" => $para ];

//2024-12-04追加
				$paramVal += ["taishoku_f" => 0 ];
				$paramVal += ["non_disp_f" => 0 ];

				break;
			case "user":
				//ユーザー抽出準備
				//全角空白を半角空白に置換
				$filter_data = preg_replace("/( |　)/", " ", $para);
				//半角空白で分割
				$filter_data = explode(" ", $filter_data);
				//半角空白が入ったvalueを削除
				$filter_data = array_filter($filter_data);
				//配列番号の採番(半角空白が存在した場合、削除されるため配列番号が飛び飛びになってしまうため)
				$filter = array_values($filter_data);

				$filter_name = "user_name";
				$filter_sql = "";
				$paramVal = [];
				foreach($filter as $i => $item) {
					$filter_sql .= ($filter_sql != "") ? " or " : "";
					//文字内の空白を削除 [半角スペース/全角スペース]ともに
					$filter_sql .= " replace(replace(trim(b.[社員名]),' ',''),'　','') like :{$filter_name}{$i}";

					$paramVal += ["{$filter_name}{$i}" => "%".$item."%" ];
				}
				$filter_sql = "(" . $filter_sql . ")";

				$sql  = "select [user_id] = a.[ユーザーID]";
				$sql .= " ,[user_name] = b.[社員名]";
				$sql .= " from [M_User] a";
				$sql .= "   inner join [C_社員] b";
				$sql .= "      on b.[社員CD] = a.[社員CD]";
				$sql .= " where " . $filter_sql;

//2024-12-04追加
				$sql .= " and (b.[退職フラグ] = :taishoku_f)";
				$sql .= " and (b.[非表示区分] = :non_disp_f)";

				$sql .= " order by a.[ユーザーID]";

//2024-12-04追加
				$paramVal += ["taishoku_f" => 0 ];
				$paramVal += ["non_disp_f" => 0 ];

				break;
			case "shisetsu_group":
				//施設抽出
				$sql  = "select [user_id] = [shisetsu_id]";
				$sql .= " ,[user_name] = [shisetsu_name]";
				$sql .= " ,[disp_order]";
				$sql .= " from [m_shisetsu]";
				$sql .= " where [shisetsu_group_id] = :shisetsu_group_id";
				$paramVal = ["shisetsu_group_id" => $para ];
				$rst = $this->dbs->fetchAllAssociative($sql,$paramVal);
				if ($rst != false) {
					for ($i=0;$i<count($rst);$i++) {
						$list_data[] = [ "type"        => 1					//1:施設
										,"code"        => $rst[$i]["user_id"]
										,"name"        => $rst[$i]["user_name"]
										,"disp_order"  => intval($rst[$i]["disp_order"])
										];
					}
				}
		}

		if ($mode == "group" || $mode == "user") {
			//ユーザー抽出
			$rst_user = $this->dbs->fetchAllAssociative($sql,$paramVal);
			$num = ($mode == "group") ? 1 : 0;
			if ($rst_user != false) {
				for ($i=0;$i<count($rst_user);$i++) {
					$num++;
					$list_data[] = [ "type"  => 2						//2:ユーザー
									,"code"    => $rst_user[$i]["user_id"]
									,"name"  => $rst_user[$i]["user_name"]
									,"disp_order"  => $num
									];
				}
			}
		}
		
		return ["list_data" => $list_data ];

	}

//2025-08-19追加
	//テンポラリーテーブル作成
    private function fn_temp_table_create($msg_no) {

		//msg作成
		$temp_msg = "##temp_msg" . uniqid();
		$sql  = "IF EXISTS (select name from tempdb..sysobjects";
		$sql .= " WHERE id = OBJECT_ID('tempdb..".$temp_msg."') and type = 'U')";
		$sql .= " drop table [{$temp_msg}];";
		$sql .= " select * into {$temp_msg}";
		$sql .= " from [t_msg]";
		$sql .= " where msg_no = :msg_no;";
		$paramVal = ["msg_no" => $msg_no ];
		//$ret = $this->dbs->executeStatement($sql,$paramVal);

		//comment作成
		$temp_msg_comment = "##temp_msg_comment" . uniqid();
		$sql  = "IF EXISTS (select name from tempdb..sysobjects";
		$sql .= " WHERE id = OBJECT_ID('tempdb..".$temp_msg_comment."') and type = 'U')";
		$sql .= " drop table [{$temp_msg_comment}];";
		$sql .= " select * into {$temp_msg_comment}";
		$sql .= " from [t_msg_comment]";
		$sql .= " where msg_no = :msg_no;";
		$paramVal = ["msg_no" => $msg_no ];
		$ret = $this->dbs->executeStatement($sql,$paramVal);

		//msg_user作成
		$temp_msg_user = "##temp_msg_user" . uniqid();
		$sql  = "IF EXISTS (select name from tempdb..sysobjects";
		$sql .= " WHERE id = OBJECT_ID('tempdb..".$temp_msg_user."') and type = 'U')";
		$sql .= " drop table [{$temp_msg_user}];";
		$sql .= " select * into {$temp_msg_user}";
		$sql .= " from [t_msg_user]";
		$sql .= " where msg_no = :msg_no;";
		$paramVal = ["msg_no" => $msg_no ];
		$ret = $this->dbs->executeStatement($sql,$paramVal);

		//msg_action作成
		$temp_msg_action = "##temp_msg_action" . uniqid();
		$sql  = "IF EXISTS (select name from tempdb..sysobjects";
		$sql .= " WHERE id = OBJECT_ID('tempdb..".$temp_msg_action."') and type = 'U')";
		$sql .= " drop table [{$temp_msg_action}];";
		$sql .= " select * into {$temp_msg_action}";
		$sql .= " from [t_msg_action]";
		$sql .= " where msg_no = :msg_no;";
		$paramVal = ["msg_no" => $msg_no ];
		$ret = $this->dbs->executeStatement($sql,$paramVal);
		
//		$temp_table = [ "msg"         => $temp_msg
		$temp_table = [ "msg_comment" => $temp_msg_comment
					   ,"msg_user"    => $temp_msg_user
					   ,"msg_action"  => $temp_msg_action
					  ];

		return $temp_table;
	}

//2025-08-19追加
	//テンポラリーテーブル削除
    private function fn_temp_table_delete($temp_table) {

		$temp_msg_comment = $temp_table["msg_comment"];
		$temp_msg_user = $temp_table["msg_user"];
		$temp_msg_action = $temp_table["msg_action"];

		$sql  = "IF EXISTS (select name from tempdb..sysobjects";
		$sql .= " WHERE id = OBJECT_ID('tempdb..".$temp_msg_comment."') and type = 'U')";
		$sql .= " drop table [{$temp_msg_comment}];";
		$sql  = "IF EXISTS (select name from tempdb..sysobjects";
		$sql .= " WHERE id = OBJECT_ID('tempdb..".$temp_msg_user."') and type = 'U')";
		$sql .= " drop table [{$temp_msg_user}];";
		$sql .= "IF EXISTS (select name from tempdb..sysobjects";
		$sql .= " WHERE id = OBJECT_ID('tempdb..".$temp_msg_action."') and type = 'U')";
		$sql .= " drop table [{$temp_msg_action}];";

		$ret = $this->dbs->executeStatement($sql);
	}
	
}