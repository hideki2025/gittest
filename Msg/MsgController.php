<?php

namespace App\Controller\Msg;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;
use Doctrine\Persistence\ManagerRegistry;

use App\Controller\Common\BreadCrumbModule;
use App\Controller\Common\ComboModule;
use App\Controller\Common\FilterModule;
use App\Controller\Msg\MsgModule;
use App\Controller\Parts\PartsReactionModule;

#[Route('/msg')]
class MsgController extends AbstractController
{
	private $dbs;
    public function __construct(private RequestStack $requestStack
					,ManagerRegistry $doctrine
					,private readonly BreadCrumbModule $bm
    				,private readonly ComboModule $cbms
    				,private readonly FilterModule $filter_s
    				,private readonly PartsReactionModule $prm
    				,private readonly MsgModule $msm)
    {
		$this->dbs = $doctrine->getConnection('remote');
    }

//2024-11-09追加
    #[Route(path: '/init', name: 'app_msg_init')]
    public function init(Request $request): Response
    {
		$form_id = "msg_list";

//2025-06-02追加
		//リンク情報クリア
		if ($this->requestStack->getSession()->has('link_kind')) {
			$this->requestStack->getSession()->remove('link_kind');
		}
		if ($this->requestStack->getSession()->has('link_no')){
			$this->requestStack->getSession()->remove('link_no');
		}

		//パンくず編集
		$bread_array = $this->requestStack->getSession()->get('bread_array');
		$num = array_search($form_id,array_column($bread_array,"form_id"));
		if ($num !== false) {
			//削除
			unset($bread_array[$num]);
			$this->requestStack->getSession()->set('bread_array',$bread_array);
		}

		//DataTablesパラメータの削除
		$this->filter_s->del_datatables_para($form_id);

		if ($this->requestStack->getSession()->has('gw_msg/folder_id')) {
			$this->requestStack->getSession()->remove('gw_msg/folder_id');
		}
		if ($this->requestStack->getSession()->has('gw_msg/msg_no')) {
			$this->requestStack->getSession()->remove('gw_msg/msg_no');
		}
		
		return $this->redirectToRoute('app_msg',["nav" => "-"]);

    }

    #[Route(path: '/i/{kokyaku_cd}/{caller}/{nav}',defaults:["kokyaku_cd" => "0","caller" => "-","nav" => ""], name: 'app_msg')]
    public function index(Request $request,$kokyaku_cd,$caller,$nav): Response
    {

//2025-06-02追加
		//リンク情報クリア
		if ($this->requestStack->getSession()->has('link_kind')) {
			$this->requestStack->getSession()->remove('link_kind');
		}
		if ($this->requestStack->getSession()->has('link_no')){
			$this->requestStack->getSession()->remove('link_no');
		}
		if ($this->requestStack->getSession()->has('upd_link_no')){
			$this->requestStack->getSession()->remove('upd_link_no');
		}
//2024-10-30追加
		if ($this->requestStack->getSession()->has('cross_yotei_no')){
			$this->requestStack->getSession()->remove('cross_yotei_no');
		}
//2025-050-26追加
		if ($this->requestStack->getSession()->has('cross_msg_no')){
			$this->requestStack->getSession()->remove('cross_msg_no');
		}
		
		$form_id = "msg_list";

		//トップメニューより遷移の場合のみセッション登録
		if ($nav == "-"){
			$this->requestStack->getSession()->set('active_menu',"msg");
		}

		//戻り先IDの設定
		$url = $request->getRequestUri();

		//パンくず設定
		$this->bm->setBbreadCrumb($url,$form_id,$nav,"",$caller);

		$this->requestStack->getSession()->set('page','app_msg');
		$this->requestStack->getSession()->set('menu_filter_mode',"list");
		$this->requestStack->getSession()->set('menu_kokyaku_cd',$kokyaku_cd);
		return $this->redirectToRoute('app_index');

    }
    #[Route(path: '/top/msg/{midoku}/', defaults:["midoku" => "0"],name: 'app_msg_top')]
    public function top_link(Request $request,$midoku): Response
    {

		$form_id = "msg_list";

//2024-11-08追加
		//DataTablesパラメータの削除
		$this->filter_s->del_datatables_para($form_id);

//2025-04-20追加
		if ($this->requestStack->getSession()->has('gw_msg/folder_id')) {
			$this->requestStack->getSession()->remove('gw_msg/folder_id');
		}
		if ($this->requestStack->getSession()->has('gw_msg/msg_no')) {
			$this->requestStack->getSession()->remove('gw_msg/msg_no');
		}

		//戻り先IDの設定
		$url = $request->getRequestUri();

		//パンくず設定
		$this->bm->setBbreadCrumb($url,$form_id,"-","","");

		$this->requestStack->getSession()->set('page','app_msg');

		//顧客からのリンクでないので
		$this->requestStack->getSession()->set('menu_filter_mode',"top");
		$this->requestStack->getSession()->set('menu_filter_midoku',$midoku);
		$this->requestStack->getSession()->set('menu_kokyaku_cd',"0");
		return $this->redirectToRoute('app_index');

    }
    #[Route(path: '/ajax/filter', name: 'app_msg_filter_ajax',methods:["post"])]
    public function ajaxFilterPost(Request $request)
    {
	 	$form = $request->get('form');
	 	parse_str($form, $frm);

//2025-04-15追加
		$dt_start = $request->get("start");
		$dt_length = $request->get("length");
		$dt_order = $request->get("order");
		$dt_draw = $request->get("draw");

//2025-04-11追加
		$para = [ "frm"    => $frm,
				  "start"  => $dt_start,
				  "length" => $dt_length,
				  "order"  => $dt_order,
				  "draw"   => $dt_draw,
				];

		$json = $this->msm->get_list_data($para);

		header("Content-Type: application/json;charset=utf-8");
		return new Response($json);

	}
    #[Route(path: '/ajax/list', name: 'app_msg_list_ajax',methods:["post"])]
    public function ajaxListPost(Request $request): Response
    {
	 	$form = $request->get('form');
	 	parse_str($form, $frm);

//2025-04-15追加
		$dt_start = $request->get("start");
		$dt_length = $request->get("length");
		$dt_order = $request->get("order");
		$dt_draw = $request->get("draw");

//2025-04-11追加
		$para = [ "frm"    => $frm,
				  "start"  => $dt_start,
				  "length" => $dt_length,
				  "order"  => $dt_order,
				  "draw"   => $dt_draw,
				];
		$ret = $this->msm->get_list_data($para);

		header("Content-Type: application/json;charset=utf-8");
		return new Response(json_encode($ret));

	}
    #[Route(path: '/ajax/update/', name: 'app_msg_update_ajax',methods:["post"])]
    public function ajaxMsgUpdatePost(Request $request): Response
    {

		$msg_no = $request->request->get('msg_no');
		$comment_no = $request->request->get('comment_no');
		$is_read = $request->request->get('is_read');
		$chg_mode = $request->request->get('chg_mode');

//2025-04-09追加
	 	$form = $request->request->get('form');
	 	parse_str($form, $frm);

		//メッセージ_ユーザー既読 更新
//2025-04-11変更(Serverside対応)
		$ret = $this->prm->fn_msg_read_update($msg_no,$comment_no,$is_read,$chg_mode,$frm);
//		$ret = $this->prm->fn_msg_read_update($para);
		header("Content-Type: application/json;charset=utf-8");
		return new Response(json_encode($ret));

	}
    #[Route(path: '/ajax/yotei_list', name: 'app_msg_yotei_list_ajax',methods:["post"])]
    public function ajaxYoteiListPost(Request $request): Response
    {
	 	$form = $request->get('form');
	 	parse_str($form, $frm);

		$ret = $this->msm->get_yotei_list_data($frm);

		header("Content-Type: application/json;charset=utf-8");
		return new Response(json_encode($ret));

	}

//2024-11-24追加
    #[Route(path: '/ajax/position', name: 'app_msg_list_position',methods:["post"])]
	public function msg_position(Request $request)
	{
		$top_p = $request->request->get('top_p');

		$this->requestStack->getSession()->set('msg_p',$top_p);
		$ret = ["status" => true ];
		
		header("Content-Type: application/json;charset=utf-8");
		return new Response(json_encode($ret));
	}
    #[Route(path: '/memo/{msg_no}/{comment_no}', name: 'app_msg_memo')]
	public function memo(Request $request,$msg_no,$comment_no){

		$memo = "";
		$sql  = "SELECT a.memo";
		$sql .= " from [t_msg_comment] a ";
		$sql .= " where a.[msg_no] =:msg_no";
		$sql .= " and a.[comment_no] =:comment_no";
		$paramVal = ["msg_no" => $msg_no ];
		$paramVal += ["comment_no" => $comment_no ];
		$rec = $this->dbs->fetchAssociative($sql,$paramVal);
		if ($rec !== false){
			$memo = $rec["memo"];
		}
        $twig = "msg/_msg_memo_box.twig";

		return $this->render($twig,[
						'memo'   => $memo
						]);
	}

}
