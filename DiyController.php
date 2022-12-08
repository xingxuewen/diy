<?php
/**
 * link: http://www.zjweyouth.com/
 * copyright: Copyright (c) 2018 浙江禾匠信息科技有限公司
 * author: wxf
 */

namespace app\modules\mch_api\controllers\diy;


use app\models\bbs\BbsArticle;
use app\models\bbs\BbsArticleReader;
use app\models\bbs\BbsComment;
use app\models\Cat;
use app\models\CombPackage;
use app\models\CombPackageGoods;
use app\models\decorate\MessageForm;
use app\models\decorate\ArticleForm;
use app\models\DistrictArr;
use app\models\Goods;
use app\models\infoflow\Article;
use app\models\infoflow\Category;
use app\models\IntegralGoodsNew;
use app\models\Live;
use app\models\LiveJoinGoods;
use app\models\Mch;
use app\models\Option;
use app\models\PreSale;
use app\models\store_form\StoreFormControl;
use app\models\PreSaleGoods;
use app\models\User;
use app\models\VoteForm;
use app\models\Votes;
use app\models\DiyTemplate;
use app\modules\mch_api\controllers\Controller;
use app\modules\mch_api\models\diy\CatForm;
use app\modules\mch_api\models\diy\DiyPageEditForm;
use app\modules\mch_api\models\diy\DiyPageForm;
use app\modules\mch_api\models\diy\DiyTemplateEditForm;
use app\modules\mch_api\models\diy\DiyTemplateForm;
use app\modules\mch_api\models\diy\GoodsForm;
use app\modules\mch_api\models\diy\NavBForm;
use app\modules\mch_api\models\diy\RubikForm;
use app\modules\mch_api\models\store_form\ControlHandler;
use app\modules\mch_api\models\store_form\StoreForm;
use app\modules\mch_api\models\v1\bargain\BargainForm;
use app\modules\mch_api\models\v1\group\GroupActivityForm;
use app\modules\mch_api\models\v1\integral\IntegralGoodsForm;
use app\weyouth\ApiResponse;
use yii\data\Pagination;
use yii\db\Expression;
use Yii;

class DiyController extends Controller
{

    //模板样式
    protected $styleData = [1, 2, 3, 4];
    public $limit = 8;
    public $page = 1;

    /**
     * @api {post} /mch_api/diy/diy/index  模板列表
     * @apiDescription  模板列表
     * @apiGroup diy
     * @apiPermission none
     * @apiVersion 3.0.0
     * @apiParam {number} store_id 店铺id
     * @apiParam {number} [page]  页码
     * @apiParam {number} [page_size]  每页展示条数
     * @apiParam {String} access_token   token
     * @apiSuccessExample {json} Success-Response:
     *   HTTPS/1.1 200 OK
     *   {
     *       "code": 200,
     *       "msg": "success",
     *       "data": {
     *           "list": [
     *               {
     *                   "id": "1",
     *                   "cover_img": "https://shoped.weiyingjia.org/uploads/template_image/2020042408543951453.png",   图片链接
     *                   "name": "默认3",   标题
     *                   "is_index": "0"   是否设置首页 0--不覆盖 1--覆盖
     *               }
     *           ],
     *           "count": "4"  总条数
     *       }
     *   }
     */
    public function actionIndex(){
        $model = new DiyTemplateForm();
        $model->attributes = \Yii::$app->request->post();
        $res = $model->getList();
        return new ApiResponse(200, 'success',[
            'list' => $res['list'],
            'count' => $res['count']
        ]);
    }

    /**
     * 添加编辑模板
     * 操作xcxmall_diy_template表
     * @return mixed
     * @throws \yii\db\Exception
     */
    public function actionEdit()
    {
        if (\Yii::$app->request->isAjax) {
            if (\Yii::$app->request->isPost) {
                //首页缓存KEY
                $indexKey = date("Ymd") . $this->store->id;
                \Yii::$app->redis->del($indexKey);

                $postData = \Yii::$app->request->post();

                //验证跳转小程序
                $res = $this->verifyJumpApplet($postData);
                if($res instanceof ApiResponse){
                    return $res;
                }

                $t = \Yii::$app->db->beginTransaction();

                if (empty($postData['template_id']) || $postData['template_id'] == 'M0'){
                    $model = new DiyTemplateEditForm();
                    $model->attributes = $postData;
                    $res = $model->save();

                    if(empty($res) || empty($res['data']['template_id']) || $res['code'] == 1){
                        $t->rollBack();
                        return new ApiResponse(201, $res['msg']??"保存失败");
                    }

                    $postData['template_id'] = $res['data']['template_id'];
                }
                //验证表单
               $res = $this->verifyForm($postData);
               if ($res instanceof ApiResponse) {
                   $t->rollBack();
                   return $res;
               }

               //验证优惠券
                $res = $this->verifyCoupon($postData);
               if($res instanceof ApiResponse){
                    $t->rollBack();
                    return $res;
               }

                $res = $this->addArticleFormContent($postData);
                if ($res instanceof ApiResponse) {
                    $t->rollBack();
                    return $res;
                }
                //验证表单
                $res = $this->addMessage($postData);
                if ($res instanceof ApiResponse) {
                    $t->rollBack();
                    return $res;
                }

                //验证商品控件
                $res = $this->verifyGoods($postData);
                if ($res instanceof ApiResponse) {
                    $t->rollBack();
                    return $res;
                }

                $model = new DiyTemplateEditForm();
                $model->attributes = $postData;
                $res = $model->save();

                $t->commit();
                return $res;

            }

            if (\Yii::$app->request->isGet) {
                $model = new DiyTemplateForm();
                $model->id = \Yii::$app->request->get('template_id');

                $res = $model->detail($this->is_admin);
                foreach ($res['data']['detail']['template'] as $k => &$v) {
                    if ($v['type'] == 'live') {
                        $v['param']['list1'] = Live::find()->alias('l')->select('l.*, (select count(1) from ' . LiveJoinGoods::tableName() . ' lj where lj.room_id = l.roomid) as num')
                            ->where(['l.store_id' => $this->store->id])->asArray()->all();
                    }
                    if($v['type'] == 'content'){
                        if(isset($v['param']['articleId'])){
                            $articleData = array_filter(explode(',',$v['param']['articleId']));
                            $v['param']['article_list'] = Article::getDataArray($this->store->id,$articleData);
                        }
                        if (!isset($v['param']['page_pad'])) {
                            $v['param']['page_pad'] = 15;
                        }
                        if (!isset($v['param']['goods_pad'])) {
                            $v['param']['goods_pad'] = 10;
                        }
                        if (!isset($v['param']['per'])) {
                            $v['param']['per'] = 1;
                        }
                    }
                    if($v['type'] == 'goods'){
                        if(!isset($v['param']['bg_color'])) {
                            $v['param']['bg_color'] = '#ffffff';
                        }
                        if(!isset($v['param']['bg_style'])) {
                            $v['param']['bg_style'] = 2;
                        }
                        if (!isset($v['param']['page_pad'])) {
                            $v['param']['page_pad'] = 15;
                        }
                        if (!isset($v['param']['goods_pad'])) {
                            $v['param']['goods_pad'] = 10;
                        }
                    }
                    if($v['type'] == 'integral'){
                        $goodsList = $v['param']['list'][0]['goods_list'];//积分商城装修逻辑已被删除的商品
                        //查询已被删除的数据
                        $findDeleted = IntegralGoodsNew::find()->where(['store_id'=>$this->store->id,'is_deleted'=>1])->select(['id'])->column();
                        foreach($goodsList as $ka=>$va){
                            if(in_array($va['activity_id'],$findDeleted)){
                                //去除已被删除的商品信息
                                unset($goodsList[$ka]);
                            }
                        }
                        $v['param']['list'][0]['goods_list'] = $goodsList;
                    }
                }
                $res['template'] = isset($res['data']['template_id']) ? $res['data']['template_id'] : '';
                return $res;
            }
        }
        \Yii::$app->view->params['accessToken'] = $this->accessToken;
        \Yii::$app->view->params['storeId'] = $this->store->id;

        return $this->render('edit');
    }

    /**
     * 验证商品控件
     * @param array $postData
     * @return ApiResponse
     */
    private function verifyGoods(array $postData)
    {
        $list = json_decode($postData['list'], 1);
        foreach ($list as $key => $val) {
            if ($val['type'] == 'goods') {
                if (count($val['param']['list']) <= 1) {
                    continue;
                }
                foreach ($val['param']['list'] as $catInfo) {
                    if (empty($catInfo['group_name'])) {
                        return new ApiResponse(201, "分组名称不能为空");
                    }
                }
            }
        }
    }

    /**
     * @param $postData
     * @return ApiResponse
     * 添加文章内容
     */
    public function addArticleFormContent(&$postData)
    {
        $tempId = $postData['template_id'];
        $ArticleList = ArticleForm::find()
            ->select('id')
            ->where(['temp_id' => $tempId, 'status' => 1])
            ->asArray()
            ->all();
        $articleIdData = array_column($ArticleList, 'id');
        $list = json_decode($postData['list'], 1);
        foreach ($list as $key => $val) {
            if ($val['type'] == 'content') {
                if (in_array($val['id'], $articleIdData)) {
                    //修改表单信息
                    $res = $this->addContent($val['param'], $tempId, $val['id']);
                    if ($res instanceof ApiResponse) {
                        return $res;
                    }
                    unset($articleIdData[$val['id']]);
                } else {
                    //新增表单
                    $res = $this->addContent($val['param'], $tempId);
                    if ($res instanceof ApiResponse) {
                        return $res;
                    }
                    $list[$key]['id'] = $res;
                }
            }
        }
        $postData['list'] = json_encode($list);
        if(!empty($articleIdData)){
            ArticleForm::updateAll(['status' => 2], ['in', 'id', $articleIdData]);
        }
    }


    /**
     * @param $postInfo
     * @param $tempId
     * @param $articleFormId
     * @return ApiResponse
     * 添加内容
     */
    private function addContent($postInfo, $tempId, $articleFormId = '')
    {
        $data['template_name'] = $postInfo['template_name'] ?? "";
        $data['style'] = $postInfo['style'] ?? "";
        $data['articleId'] = $postInfo['articleId'] ?? "";
        $data['more_display'] = $postInfo['more_display'] ?? "";
        $data['categoryId'] = $postInfo['categoryId'] ?? "";
        $data['background_color'] = $postInfo['background_color'] ?? "";
        if (empty($data['template_name'])) {
            return new ApiResponse(201, "模板名称不能为空");
        }
        if (empty($data['style'])) {
            return new ApiResponse(201, "模板样式不能为空");
        }
        if (!in_array($data['style'], $this->styleData)) {
            return new ApiResponse(201, "模板样式类型传入错误");
        }
        if (empty($data['articleId'])) {
            return new ApiResponse(201, "文章内容不能为空");
        }
        $articleData = array_filter(explode(',', $data['articleId']));
        $articleModel = new Article();
        $endData = $articleModel->getDataArray($this->store->id, $articleData);
        if (count($endData) != count($articleData)) {
            return new ApiResponse(201, "文章ID传入错误");
        }
        if (isset($data['more_display']) && $data['more_display'] == 1) {
            if (!isset($data['categoryId']) || empty($data['categoryId'])) {
                return new ApiResponse(201, "分类不能为空");
            }
            $categoryId = $data['categoryId'][count($data['categoryId']) - 1];
            $result = Category::getInfoFlowCategory($categoryId, $this->store->id);
            if (empty($result)) {
                return new ApiResponse(201, "分类ID传入错误");
            }
        }
        if (!isset($data['background_color'])) {
            return new ApiResponse(201, "背景颜色不能为空");
        }
        $data['temp_id'] = $tempId;
        return ArticleForm::addArticleForm($this->store->id, $data, $tempId, $articleFormId);
    }

    /**
     * 表单验证
     * @param array $postData 模版数据
     * @return ApiResponse|bool
     */
    // public function verifyForm(&$postData){
    //     //获取保存的表单信息
    //     $tempId = $postData['template_id'];

    //     $formList = \app\models\store_form\StoreForm::find()->select('id')->where(['temp_id' => $tempId, 'status' => 1])->asArray()->all();

    //     $formIds = array();
    //     foreach ($formList as $val){
    //         $formIds[$val['id']] = $val['id'];
    //     }

    //     //获取提交的表单信息
    //     $list = json_decode($postData['list'], 1);
    //     foreach ($list as $key=>$val){
    //         if($val['type'] == 'form'){

    //             if(in_array($val['id'], $formIds)){
    //                 //修改表单信息
    //                 $res = $this->createForm($val['param'],$tempId, $val['id']);
    //                 if($res instanceof ApiResponse){
    //                     return $res;
    //                 }
    //                 $val['id'] = $res;
    //                 unset($formIds[$val['id']]);
    //             }else{
    //                 //新增表单
    //                 $res = $this->createForm($val['param'],$tempId);
    //                 if($res instanceof ApiResponse){
    //                     return $res;
    //                 }
    //                 $val['id'] = $res;
    //             }

    //             $list[$key] = $val;
    //         }
    //     }
    //     $postData['list'] = json_encode($list);

    //     if(!empty($formIds)){
    //         //删除的表单修改状态
    //         \app\models\store_form\StoreForm::updateAll(['status' => 2], ["IN",'id',$formIds]);
    //     }
    // }

    /**
     * 创建表单
     * @param array $formData 表单数据
     * @param string $tempId 模版ID
     * @param int $formId 表单ID
     * @return ApiResponse|bool
     */
    // public function createForm(array &$formData,string $tempId, int $formId = 0)
    // {

    //     if (empty($formData) || empty($formData['control_data'])) {
    //         return new ApiResponse(201, "表单内容为空");
    //     }

    //     $styleSetting = $formData['style_setting'];

    //     if (!isset($styleSetting['fill_background']) || empty($styleSetting['fill_background'])) {
    //         return new ApiResponse(201, "填充背景数据未提交");
    //     }
    //     if (!isset($styleSetting['fill_background_color'])) {
    //         return new ApiResponse(201, "填充背景自定义数据未提交");
    //     }
    //     if (!isset($styleSetting['page_margin']) || empty($styleSetting['page_margin'])) {
    //         return new ApiResponse(201, "页面间隔数据未提交");
    //     }
    //     if (!isset($styleSetting['page_margin_num']) || empty($styleSetting['page_margin_num'])) {
    //         return new ApiResponse(201, "页面间隔数值未提交");
    //     }
    //     if (!isset($styleSetting['margin_top']) || empty($styleSetting['margin_top'])) {
    //         return new ApiResponse(201, "上边距数据未提交");
    //     }
    //     if (!isset($styleSetting['margin_top_num']) || empty($styleSetting['margin_top_num'])) {
    //         return new ApiResponse(201, "上边距数值未提交");
    //     }
    //     if (!isset($styleSetting['margin_bottom']) || empty($styleSetting['margin_bottom'])) {
    //         return new ApiResponse(201, "下边距数据未提交");
    //     }
    //     if (!isset($styleSetting['margin_bottom_num']) || empty($styleSetting['margin_bottom_num'])) {
    //         return new ApiResponse(201, "下边距数值未提交");
    //     }

    //     $contentSetting = $formData['content_setting'];

    //     if (!isset($contentSetting['module_name_hide'])) {
    //         return new ApiResponse(201, "模块名称是否隐藏数据未提交");
    //     }
    //     if ($contentSetting['module_name_hide'] == 1) {
    //         if (!isset($contentSetting['module_name']) || empty($contentSetting['module_name'])) {
    //             return new ApiResponse(201, "模块名称不能为空");
    //         }
    //     }
    //     if (!isset($contentSetting['button_name']) || empty($contentSetting['button_name'])) {
    //         return new ApiResponse(201, "按钮文案不能为空");
    //     }

    //     if(!empty($contentSetting['app_id'])){
    //         //如果有app id 就判断是否设置了 跳转页面
    //         if (!isset($contentSetting['jump_path']) || empty($contentSetting['jump_path'])) {
    //             return new ApiResponse(201, "跳转路径未设置");
    //         }
    //     }else{
    //         if (!isset($contentSetting['jump_url']) || empty($contentSetting['jump_url'])) {
    //             return new ApiResponse(201, "跳转页面未设置");
    //         }
    //     }

    //     foreach ($formData['control_data'] as $key => $data) {
    //         $control = StoreForm::getControlClass($data['type']);

    //         if (empty($control)) {
    //             return new ApiResponse(201, "表单内容错误");
    //         }

    //         $res = (new ControlHandler($control))->checkData($data);
    //         if ($res instanceof ApiResponse) {
    //             return $res;
    //         }
    //     }

    //     $isUpdate = false;
    //     //如果是修改，查询之前的数据，看是否有变动
    //     if (!empty($formId)) {
    //         $oFormData = \app\models\store_form\StoreForm::find()->where(["id" => $formId])->asArray()->one();

    //         $oFormDataInfo = json_decode($oFormData['form_data'], 1);

    //         $controlIds = array_column($formData['control_data'], 'id');

    //         foreach ($controlIds as $controlId) {
    //             if ($controlId == 0) {
    //                 $isUpdate = true;
    //             }
    //         }
    //         if (!$isUpdate) {
    //             $controls = array_column($formData['control_data'], null, 'id');
    //             foreach ($oFormDataInfo['control_data'] as $data) {

    //                 $control = StoreForm::getControlClass($data['type']);
    //                 if (empty($control)) {
    //                     return new ApiResponse(201, "表单内容错误");
    //                 }

    //                 if (empty($controls[$data['id']])) {
    //                     $isUpdate = true;
    //                     break;
    //                 }

    //                 $res = (new ControlHandler($control))->checkUpdate($data, $controls[$data['id']]);
    //                 if ($res) {
    //                     $isUpdate = true;
    //                     break;
    //                 }
    //             }
    //         }
    //     }

    //     //如果是修改，且表单数据有变动，把所有ID重置为0，表示新增
    //     if ($isUpdate) {
    //         $formId = 0;
    //         foreach ($formData['control_data'] as $key => $data) {
    //             $data['id'] = 0;
    //             $formData['control_data'][$key] = $data;
    //         }
    //     }

    //     $ret = false;
    //     if (!empty($formId)) {
    //         $ret = StoreForm::updateStoreForm($formId, $formData);
    //         if ($ret === false) {
    //             return new ApiResponse(201, "保存失败");
    //         }
    //         $ret = $formId;
    //     } else {
    //         $ret = StoreForm::addStoreForm($this->store->id, $formData, $tempId);
    //         if (empty($ret)) {
    //             return new ApiResponse(201, "添加失败");
    //         }
    //         $formId = $ret;
    //     }

    //     //修改当前表单的所有控件为未启用
    //     StoreFormControl::updateAll(['status' => 2], ['form_id' => $formId]);

    //     //添加表单需要保存表单控件数据
    //     foreach ($formData['control_data'] as $key => $data) {

    //         $control = StoreForm::getControlClass($data['type']);

    //         if (empty($control)) {
    //             return new ApiResponse(201, "表单内容错误");
    //         }

    //         $res = (new ControlHandler($control))->saveControl($formId, $data);
    //         if ($res instanceof ApiResponse) {
    //             return $res;
    //         }

    //         $data['id'] = $res;
    //         $formData['control_data'][$key] = $data;
    //     }

    //     $res = \app\models\store_form\StoreForm::updateAll(['form_data' => json_encode($formData)], ['id' => $formId]);

    //     return $ret;
    // }

    /**
     * @param $postData
     * @return ApiResponse
     * 添加留言板组件
     */
    public function addMessage(&$postData)
    {
        $tempId = $postData['template_id'];
        $ArticleList = MessageForm::find()
            ->select('id')
            ->where(['temp_id' => $tempId, 'status' => 1])
            ->asArray()
            ->all();
        $messageFormIdData = array_column($ArticleList, 'id');
        $list = json_decode($postData['list'], 1);
        foreach ($list as $key => $val) {
            if ($val['type'] == 'message') {
                if (in_array($val['id'], $messageFormIdData)) {
                    //修改表单信息
                    $res = $this->createMessageForm($val['param'], $tempId, $val['id']);
                    if ($res instanceof ApiResponse) {
                        return $res;
                    }
                    unset($messageFormIdData[$val['id']]);
                } else {
                    //新增表单
                    $res = $this->createMessageForm($val['param'], $tempId,'');
                    if ($res instanceof ApiResponse) {
                        return $res;
                    }
                    $list[$key]['id'] = $res;
                }
            }
        }
        $postData['list'] = json_encode($list);
        if(!empty($messageFormIdData)){
            MessageForm::updateAll(['status' => 2], ['in', 'id', $messageFormIdData]);
        }

    }


    /**
     * @param $postInfo
     * @param $tempId
     * @param $messageFormId
     * @return ApiResponse
     * 创建留言板组件
     */
    private function createMessageForm($postInfo, $tempId, $messageFormId = '')
    {
        $data['template_name'] = $postInfo['template_name'] ?? "";
        $data['subtitle'] = $postInfo['subtitle'] ?? "";
        $data['show_subtitle'] = $postInfo['show_subtitle'] ?? "";
        $data['message_content'] = $postInfo['message_content'] ?? "";
        $data['message_type'] = $postInfo['message_type'] ?? "";
        $data['content'] = $postInfo['content'] ?? "";
        $data['contact_information'] = $postInfo['contact_information'] ?? "";
        $data['show_more'] = $postInfo['show_more'] ?? "";
        $data['background'] = $postInfo['background'] ?? "1";
        $data['message_display'] = $postInfo['message_display'] ?? "1";
        if (empty($data['template_name'])) {
            return new ApiResponse(201, "模板名称不能为空");
        }
        if ($data['show_subtitle'] == 1) {
            if (empty($data['subtitle'])) {
                return new ApiResponse(201, "副标题不能为空");
            }
        }
        $messageContentData = json_decode($data['message_content'], true);
        foreach ($messageContentData as $k => $v) {
            if ($v['type'] == 'message_content' || $v['type'] == 'contact_information ') {
                if (isset($v['is_require']) || $v['is_require'] != 1) {
                    return new ApiResponse(201, "联系方式和留言内容是必填项不能为空");
                }
            }
        }
        if (isset($data['background']) && empty($data['background'])) {
            return new ApiResponse(201, "背景颜色不能为空");
        }
        return MessageForm::addMessageForm($this->store->id, $data, $tempId, $messageFormId);
    }

    /**
     * 表单验证
     * @param array $postData 模版数据
     * @return ApiResponse|bool
     */
    public function verifyForm(&$postData){
        //获取保存的表单信息
        $tempId = $postData['template_id'];

        $formList = \app\models\store_form\StoreForm::find()->select('id')->where(['temp_id' => $tempId, 'status' => 1])->asArray()->all();

        $formIds = array();
        foreach ($formList as $val){
            $formIds[$val['id']] = $val['id'];
        }

        //获取提交的表单信息
        $list = json_decode($postData['list'], 1);
        foreach ($list as $key=>$val){
            if($val['type'] == 'form'){
                if(in_array($val['id'], $formIds)){
                    //修改表单信息
                    $res = $this->createForm($val['param'],$tempId, $val['id']);
                    if($res instanceof ApiResponse){
                        return $res;
                    }
                    $val['id'] = $res;
                    unset($formIds[$val['id']]);
                }else{
                    //新增表单
                    $res = $this->createForm($val['param'],$tempId);
                    if($res instanceof ApiResponse){
                        return $res;
                    }
                    $val['id'] = $res;
                }

                $list[$key] = $val;
            }
        }
        $postData['list'] = json_encode($list);

        if(!empty($formIds)){
            //删除的表单修改状态
            \app\models\store_form\StoreForm::updateAll(['status' => 2], ["IN",'id',$formIds]);
        }
    }

    /**
     * 优惠券验证
     * @param array $postData 模版数据
     * @return ApiResponse|bool
     */
    public function verifyCoupon(&$postData){
        //获取提交的表单信息
        $list = json_decode($postData['list'], 1);
        foreach ($list as $key=>$val){
            if($val['type'] == 'coupon' && empty($val['param']['coupon_list'])){
                return new ApiResponse(201,"优惠券控件必须选择一个优惠券");
            }
        }
    }


    /**
     * 验证跳转小程序
     * @param array $postData 模版数据
     * @return ApiResponse|bool
     */
    public function verifyJumpApplet($postData){
        //获取提交的表单信息
        $list = json_decode($postData['list'], 1);
        foreach ($list as $key=>$val){
            //导航图标
            if($val['type'] == 'nav'){
                $param_list = $val['param']['list'];
                foreach($param_list as $pk=>$pv){
                    if($pv['page_name'] == '跳转小程序'){
                        $app_id = $pv['app_id'] ?? '';
                        $app_url = $pv['app_url'] ?? '';
                        if(empty($app_id)){
                            return new ApiResponse(201, "轮播广告跳转小程序必须设置AppId" );
                        }
                    }
                }
                $default_list = $val['param']['default_list'][0];
                foreach($default_list as $dk=>$dv){
                    if($dv['page_name'] == '跳转小程序'){
                        $app_id = $dv['app_id'] ?? '';
                        $app_url = $dv['app_url'] ?? '';
                        if(empty($app_id)){
                            return new ApiResponse(201, "轮播广告跳转小程序必须设置AppId" );
                        }
                    }
                }
            }
            //轮播广告
            if($val['type'] == 'banner'){
                $param_list = $val['param']['list'];
                foreach($param_list as $bk=>$bv){
                    if($bv['page_name'] == '跳转小程序'){
                        $app_id = $bv['app_id'] ?? '';
                        $app_url = $bv['app_url'] ?? '';
                        if(empty($app_id)){
                            return new ApiResponse(201, "轮播广告跳转小程序必须设置AppId" );
                        }
                    }
                }
            }
            //图片广告
            if($val['type'] == 'rubik'){
                $param_list = $val['param']['list'];
                foreach($param_list as $rk=>$rv){
                    if($rv['page_name'] == '跳转小程序'){
                        $app_id = $rv['app_id'] ?? '';
                        $app_url = $rv['app_url'] ?? '';
                        if(empty($app_id)){
                            return new ApiResponse(201, "轮播广告跳转小程序必须设置AppId" );
                        }
                    }
                }
            }
        }
    }


    /**
     * 创建表单
     * @param array $formData 表单数据
     * @param string $tempId 模版ID
     * @param int $formId 表单ID
     * @return ApiResponse|bool
     */
    public function createForm(array &$formData,string $tempId, int $formId = 0)
    {

        if (empty($formData) || empty($formData['control_data'])) {
            return new ApiResponse(201, "表单内容为空");
        }

        $styleSetting = $formData['style_setting'];

        if (!isset($styleSetting['fill_background']) || empty($styleSetting['fill_background'])) {
            return new ApiResponse(201, "填充背景数据未提交");
        }
        if (!isset($styleSetting['fill_background_color'])) {
            return new ApiResponse(201, "填充背景自定义数据未提交");
        }
        if (!isset($styleSetting['page_margin']) || empty($styleSetting['page_margin'])) {
            return new ApiResponse(201, "页面间隔数据未提交");
        }
        if (!isset($styleSetting['page_margin_num']) || empty($styleSetting['page_margin_num'])) {
            return new ApiResponse(201, "页面间隔数值未提交");
        }
        if (!isset($styleSetting['margin_top']) || empty($styleSetting['margin_top'])) {
            return new ApiResponse(201, "上边距数据未提交");
        }
        if (!isset($styleSetting['margin_top_num']) || empty($styleSetting['margin_top_num'])) {
            return new ApiResponse(201, "上边距数值未提交");
        }
        if (!isset($styleSetting['margin_bottom']) || empty($styleSetting['margin_bottom'])) {
            return new ApiResponse(201, "下边距数据未提交");
        }
        if (!isset($styleSetting['margin_bottom_num']) || empty($styleSetting['margin_bottom_num'])) {
            return new ApiResponse(201, "下边距数值未提交");
        }

        $contentSetting = $formData['content_setting'];

        if (!isset($contentSetting['module_name_hide'])) {
            return new ApiResponse(201, "模块名称是否隐藏数据未提交");
        }
        if ($contentSetting['module_name_hide'] == 1) {
            if (!isset($contentSetting['module_name']) || empty($contentSetting['module_name'])) {
                return new ApiResponse(201, "模块名称不能为空");
            }
        }
        if (!isset($contentSetting['button_name']) || empty($contentSetting['button_name'])) {
            return new ApiResponse(201, "按钮文案不能为空");
        }

        if(!empty($contentSetting['app_id'])){
            //如果有app id 就判断是否设置了 跳转页面
            if (!isset($contentSetting['jump_path']) || empty($contentSetting['jump_path'])) {
                return new ApiResponse(201, "跳转路径未设置");
            }
        }else{
            if (!isset($contentSetting['jump_url']) || empty($contentSetting['jump_url'])) {
                return new ApiResponse(201, "跳转页面未设置");
            }
        }

        foreach ($formData['control_data'] as $key => $data) {
            $control = StoreForm::getControlClass($data['type']);

            if (empty($control)) {
                return new ApiResponse(201, "表单内容错误");
            }

            $res = (new ControlHandler($control))->checkData($data);
            if ($res instanceof ApiResponse) {
                return $res;
            }
        }

        $isUpdate = false;
        //如果是修改，查询之前的数据，看是否有变动
        if(!empty($formId)) {
            $oFormData = \app\models\store_form\StoreForm::find()->where(["id" => $formId])->asArray()->one();

            $oFormDataInfo = json_decode($oFormData['form_data'],1);

            $controlIds = array_column($formData['control_data'], 'id');

            foreach ($controlIds as $controlId) {
                if ($controlId == 0) {
                    $isUpdate = true;
                }
            }
            if(!$isUpdate) {
                $controls = array_column($formData['control_data'], null ,'id');
                foreach ($oFormDataInfo['control_data'] as $data) {

                    $control = StoreForm::getControlClass($data['type']);
                    if (empty($control)) {
                        return new ApiResponse(201, "表单内容错误");
                    }

                    if(empty($controls[$data['id']])){
                        $isUpdate = true;
                        break;
                    }

                    $res = (new ControlHandler($control))->checkUpdate($data, $controls[$data['id']]);
                    if ($res) {
                        $isUpdate = true;
                        break;
                    }
                }
            }
        }

        //如果是修改，且表单数据有变动，把所有ID重置为0，表示新增
        if($isUpdate){
            $formId = 0;
            foreach ($formData['control_data'] as $key => $data) {
                $data['id'] = 0;
                $formData['control_data'][$key] = $data;
            }
        }

        $ret = false;
        if(!empty($formId)){
            $ret = StoreForm::updateStoreForm($formId,$formData);
            if($ret === false){
                return new ApiResponse(201, "保存失败");
            }
            $ret = $formId;
        }else {
            $ret = StoreForm::addStoreForm($this->store->id, $formData,$tempId);
            if (empty($ret)) {
                return new ApiResponse(201, "添加失败");
            }
            $formId = $ret;
        }

        //修改当前表单的所有控件为未启用
        StoreFormControl::updateAll(['status' => 2], ['form_id' => $formId]);

        //添加表单需要保存表单控件数据
        foreach ($formData['control_data'] as $key => $data) {

            $control = StoreForm::getControlClass($data['type']);

            if (empty($control)) {
                return new ApiResponse(201, "表单内容错误");
            }

            $res = (new ControlHandler($control))->saveControl($formId, $data);
            if ($res instanceof ApiResponse) {
                return $res;
            }

            $data['id'] = $res;
            $formData['control_data'][$key] = $data;
        }

        $res = \app\models\store_form\StoreForm::updateAll(['form_data' => json_encode($formData)],['id' => $formId]);

        return $ret;

        //return new ApiResponse(200, "添加成功", array("form_id" => $formId));
    }

    /**
     * @api {post} /mch_api/diy/diy/delete  删除模板
     * @apiDescription  模板列表
     * @apiGroup diy
     * @apiPermission none
     * @apiVersion 3.0.0
     * @apiParam {number} store_id 店铺id
     * @apiParam {number} id  模板id
     * @apiParam {String} access_token   token
     * @apiSuccessExample {json} Success-Response:
     *   HTTPS/1.1 200 OK
     *   {
     *       "code": 200,
     *       "msg": "删除成功"
     *   }
     */
    public function actionDelete(){
        $model = new DiyTemplateForm();
        $model->id = (int)\Yii::$app->request->post('id');
        $res = $model->delete();
        if($res['code'] == 0){
            return new ApiResponse(200, '删除成功');
        }
        return new ApiResponse(201, $res['msg']);
    }

    /**
     * 页面列表
     * 操作xcxmall_diy_page
     *
     */
    public function actionPage()
    {
        $model = new DiyPageForm();
        $res = $model->getList();

        return $this->render('page', [
            'list' => $res['list'],
            'pagination' => $res['pagination']
        ]);
    }

    /**
     * 页面编辑、添加
     * 操作xcxmall_diy_page
     */
    public function actionPageEdit()
    {
        $id = \Yii::$app->request->get('id');
        if (\Yii::$app->request->isAjax) {
            $model = new DiyPageEditForm();
            $model->attributes = \Yii::$app->request->post();
            $model->id = $id;
            $res = $model->save();

            return $res;
        }

        $model = new DiyPageForm();
        $model->attributes = \Yii::$app->request->get();
        $res = $model->detail();


        return $this->render('page-edit', [
            'templateList' => $res['templateList'],
            'detail' => $res['detail'],
        ]);
    }

    /**
     * 页面删除
     * 操作xcxmall_diy_page
     * @return array
     */
    public function actionPageDelete()
    {
        $model = new DiyPageForm();
        $model->id = \Yii::$app->request->get('id');
        $res = $model->delete();

        return $res;
    }

    public function actionGetCat()
    {
        $form = new CatForm();
        $form->type = \Yii::$app->request->get('type');
        $form->page = \Yii::$app->request->get('page', 1);
        $form->keyword = \Yii::$app->request->get('keyword');
        $form->limit = 8;
        $d = $form->search();
        $d['data']['page_url'] .= sprintf('?access_token=%s&store_id=%d', $this->accessToken, $this->store->id);
        return $d;
    }

    public function actionGetGoods()
    {
        $form = new GoodsForm();
        $form->type = \Yii::$app->request->get('type');
        $form->page = \Yii::$app->request->get('page', 1);
        $form->cat = \Yii::$app->request->get('cat', 0);
        $form->mch = \Yii::$app->request->get('mch', 0);
        $form->limit = \Yii::$app->request->get("page_size", 10);
        $d = $form->search();
        $d['data']['page_url'] .= sprintf('?access_token=%s&store_id=%d', $this->accessToken, $this->store->id);
        return $d;
    }

    public function actionGetRubik()
    {
        $form = new RubikForm();
        $form->id = \Yii::$app->request->get('id');
        return $form->search();
    }

    /**
     * 页面状态修改（禁用启用）
     * 操作xcxmall_diy_page
     * @return array
     */
    public function actionPageUpdateStatus()
    {
        $model = new DiyPageForm();
        $model->id = \Yii::$app->request->get('id');
        $model->status = \Yii::$app->request->get('status');
        $res = $model->updateStatus();

        return $res;
    }

    /**
     * @api {post} /mch_api/diy/diy/page-update-index  设置成首页
     * @apiDescription  设置成首页
     * @apiGroup diy
     * @apiPermission none
     * @apiVersion 3.0.0
     * @apiParam {number} store_id 店铺id
     * @apiParam {number} id  模板id
     * @apiParam {number} status  状态 0取消 1设置
     * @apiParam {String} access_token   token
     * @apiSuccessExample {json} Success-Response:
     *   HTTPS/1.1 200 OK
     *   {
     *       "code": 200,
     *       "msg": "设置成功"
     *   }
     */
    public function actionPageUpdateIndex()
    {
        $model = new DiyPageForm();
        $model->template_id = (int)\Yii::$app->request->post('id');
        $model->status = (int)\Yii::$app->request->post('status');
        $res = $model->updateIndex();
        if($res['code'] == 0){
            return new ApiResponse(200,'设置成功');
        }
        return new ApiResponse(201,$res['msg']);
    }

    // 获取导航图标、轮播图
    public function actionGetNavBanner()
    {
        $form = new NavBForm();
        $form->type = \Yii::$app->request->get('type');
        $form->page = \Yii::$app->request->get('page', 1);
        $form->limit = 8;
        $d = $form->search();
        $d['data']['page_url'] .= sprintf('?access_token=%s&store_id=%d', $this->accessToken, $this->store->id);
        return $d;
    }

    public function actionGetLive()
    {
        $roomId = \Yii::$app->request->get('id');
        $query = Live::find()->alias('l');
        if ($roomId > 0) {
             $query->where("l.id = :id");
             $query->params([":id" => $roomId]);
        }
        //$c_query = LiveJoinGoods::find(['room_id' => 'l.roomid'])->count();
        //$query->from(['count' => $c_query]);
        $query->select('l.*, (select count(1) from '. LiveJoinGoods::tableName().' lj where lj.room_id = l.roomid) as num');
        $query->where(['l.store_id' => $this->store->id]);
        $liveList = $query->asArray()->all();
        return [
            'code' => 0,
            'msg' => 'success',
            'data' => $liveList
        ];
    }

    public function actionGetComb()
    {
        //$combPackageId = \Yii::$app->request->post('comb_package_id', 0);
        //$query = CombPackageGoods::find()->alias('a')->select('')->innerJoin(['b'=>Goods::tableName()], ' a.goods_id = b.id ')->innerJoin(['c'=>CombPackage::tableName()], 'a.comb_package_id = c.id')->where([ 'c.is_delete'=>0, 'c.store_id' => $this->store->id , 'b.status' => 1 ]);
        $query = CombPackage::find()->select('id,name,price,original_price,saving_price')->where(['is_delete' => 0, 'store_id' => $this->store->id]);
        //$count = $query->count();
        //$pagination = new Pagination(['totalCount' => $count, 'pageSize' => $this->limit, 'page' => $this->page - 1]);
        //$list = $query->limit($pagination->limit)->offset($pagination->offset)->asArray()->createCommand()->queryAll();

        $keyword = trim(\Yii::$app->request->get('keyword', ''));
        if($keyword != ''){
            $query->andWhere(['like', 'name', $keyword]);
        }

        //分页
        $count = $query->count();
        $page = isset($_GET['page']) ? (int)$_GET['page']: 1;
        $limit = 8;
        $pagination = new Pagination(['totalCount' => $count, 'pageSize' => $limit, 'page' => $page - 1]);
        $list = $query->limit($pagination->limit)->offset($pagination->offset)->asArray()->createCommand()->queryAll();
        foreach($list as $k=>$v){
            //获取商品信息
            $list[$k]['goods_list'] = $this->getGoodList($v['id']);
            $list[$k]['is_comb_package'] = 1;
        }
        $data = [
            'count' => $count,
            'goods_list'  => $list,
            'page' => $page,
            'page_count'=>$pagination->pageCount,
            'pagination'=>$pagination,
        ];
        $data['page_url'] = \Yii::$app->urlManager->createUrl(['mch_api/diy/diy/get-comb']);
        $data['page_url'] .= sprintf('?access_token=%s&store_id=%d', $this->accessToken, $this->store->id);
        return new ApiResponse(0, 'success', $data);
    }

    public function getGoodList($combPackageId)
    {
        $data = CombPackageGoods::find()->alias('a')->select('b.id,a.num,b.name,b.cover_pic,b.attr')->innerJoin(['b'=>Goods::tableName()], ' a.goods_id = b.id ')->where(['b.status' => 1, 'a.comb_package_id'=>$combPackageId, 'store_id'=>$this->store->id ])->asArray()->createCommand()->queryAll();
        foreach($data as $k=>$v){
            $goods = Goods::findOne($v['id']);
            $data[$k]['attr_group_list'] = $goods->getAttrGroupList();
        }
        return $data;
    }


    public function actionGetDjys()
    {
        //获取所有的
        $curTime = date('Y-m-d H:i:s');
        $query = PreSaleGoods::find()->alias('a')->select('a.pre_sale_id as id,b.id as goods_id,b.cover_pic,c.front_money,c.final_money,(c.front_money + c.final_money) as total_money, c.front_start_time, c.front_end_time, c.final_end_time, c.visit_cnt, c.join_cnt, c.is_close,c.name')->innerJoin(['b'=>Goods::tableName()], ' a.goods_id = b.id ')->innerJoin(['c'=>PreSale::tableName()], 'a.pre_sale_id = c.id')->where([ 'c.is_delete'=>0, 'c.store_id' => $this->store->id, 'b.status' => 1, 'c.is_close' => 0 ])->andWhere( ['>=', 'c.final_end_time', $curTime] );

        $keyword = trim(\Yii::$app->request->get('keyword', ''));
        if($keyword != ''){
            $query->andWhere(['like', 'c.name', $keyword]);
        }

        $count = $query->count();
        $page = isset($_GET['page']) ? (int)$_GET['page']: 1;
        $limit = 8;
        $pagination = new Pagination(['totalCount' => $count, 'pageSize' => $limit, 'page' => $page - 1]);
        $list = $query->limit($pagination->limit)->offset($pagination->offset)->asArray()->createCommand()->queryAll();
        foreach($list as $k=>$v){
            $frontStartTime = $v['front_start_time'];
            $frontEndTime   = $v['front_end_time'];
            $curTime        = date('Y-m-d H:i:s');
            if( $curTime >= $frontStartTime && $curTime <= $frontEndTime ){
                $list[$k]['is_open'] = $isOpen = 1;
                $list[$k]['title'] = $title  = '付定金';
            }elseif( $curTime < $frontStartTime ){
                $list[$k]['is_open'] = $isOpen  = 0;
                $list[$k]['title'] = $title   = '即将开售';
            }elseif( $curTime > $frontStartTime ){
                $list[$k]['is_open'] = $isOpen = 0;
                $list[$k]['title'] = $title  = '活动结束';
            }
            //活动结束时间倒计时
            $closeTime = strtotime($frontEndTime) - time();
            //浏览人数
            $list[$k]['visit_cnt'] = $visitCnt  = $v['visit_cnt'];
            //参与人数
            $list[$k]['join_cnt'] = $joinCnt   = $v['join_cnt'];
            //是否为预售
            $list[$k]['is_pre_sale'] = 1;

        }

        $data = [
            'count' => $count,
            'goods_list'  => $list,
            'page' => $page,
            'page_count'=>$pagination->pageCount,
            'pagination'=>$pagination,
        ];
        $data['page_url'] = \Yii::$app->urlManager->createUrl(['mch_api/diy/diy/get-djys']);
        $data['page_url'] .= sprintf('?access_token=%s&store_id=%d', $this->accessToken, $this->store->id);
        return new ApiResponse(0, 'success', $data);

    }

    /**
     * 获取投票主题列表
     */
    public function actionGetVote()
    {
        $vote_id = \Yii::$app->request->get('vote_id');
        $curtime = date('Y-m-d H:i:s');
        if ($vote_id > 0) {
            $liveList = VoteForm::find()
                ->alias('f')
                ->select('f.*,v.text')
                ->where(['f.vote_id' => $vote_id,'f.is_delete'=>1])
                ->leftJoin(['v' =>  Votes::tableName()], 'f.vote_id=v.id')
                ->asArray()
                ->all();
        } else {
            $liveList = Votes::find()->select('id,title,start_at,end_at,if_chose_one,text')
            ->where([
                'status' => 1,
                'is_delete' => 1,
                'store_id' => $this->store->id])
            ->andWhere(['>=', 'end_at', $curtime])
            ->asArray()
            ->all();
        }
        if($liveList){
            foreach($liveList as &$val){
                $val['text'] = json_decode($val['text'],true);
                unset($val);
            }
        }
        return [
            'code' => 0,
            'msg' => 'success',
            'data' => $liveList
        ];
    }

    //商户列表
    public function actionGetMchList()
    {
        //关键词
        $keyword = \Yii::$app->request->get('keyword','');
        //经度
        //$lng = '103.955605';
        $lng = \Yii::$app->request->get('lng','');
        //纬度
        //$lat = '30.568996';
        $lat = \Yii::$app->request->get('lat','');

        $query = Mch::find()->alias('m')->leftJoin(['u' => User::tableName()], 'm.user_id=u.id')
            ->where([
                'm.is_delete' => 0,//未删除
                'm.store_id' => $this->store->id,
                'm.review_status' => 1,//审核通过
                'm.is_open'=>1,//营业中
            ]);
        //关键词搜索
        if (trim($keyword) != '') {
            $query->andWhere([
                'OR',
                //['LIKE', 'm.realname', $keyword],
                //['LIKE', 'm.tel', $keyword],
                ['LIKE', 'm.name', $keyword],
                //['LIKE', 'u.nickname', $keyword],
            ]);
        }

        //如果开启定位,并且小程序传递的经纬度有效
        $settingData = (array)Option::get('mch_setting', $this->store->id, 'mch');
        if( isset($settingData['is_position']) ){
            $isPosition = intval($settingData['is_position']);
        }
        if($isPosition && ($lng && $lat)  ){
            $fields = new Expression("m.id,m.name,m.realname,m.summary,m.tel,m.sort,m.logo,m.header_bg,m.province_id,m.city_id,m.district_id,m.address,u.nickname,u.platform,u.avatar_url,if(latitude and longitude, round((2 * 6378.137* ASIN(SQRT(POW(SIN(3.1415926535898*(".$lat."-latitude)/360),2)+COS(3.1415926535898*".$lat."/180)* COS(latitude * 3.1415926535898/180)*POW(SIN(3.1415926535898*(".$lng."-longitude)/360),2)))),2) , 99999999) as distance");
            $orderSql = 'distance ASC,m.id DESC';
        }else{
            //如果不开启定位
            $fields = "m.id,m.name,m.realname,m.summary,m.tel,m.sort,m.logo,m.header_bg,m.province_id,m.city_id,m.district_id,m.address,u.nickname,u.platform,u.avatar_url";
            $orderSql = 'm.sort,m.id DESC';
        }
        //$list = $query->orderBy($orderSql)->limit($page_size)->offset($offet)->select( $fields  )->asArray()->createCommand()->queryAll();


        $count = $query->count();
        $page = isset($_GET['page']) ? (int)$_GET['page']: 1;
        $limit = isset($_GET['page_size']) ? (int)$_GET['page_size']: 8;
        $pagination = new Pagination(['totalCount' => $count, 'pageSize' => $limit, 'page' => $page - 1]);
        $list = $query->limit($pagination->limit)->offset($pagination->offset)->asArray()->createCommand()->queryAll();
        //获取地址信息
        foreach($list as &$val) {
            $val['province_id'] = DistrictArr::getDistrictName($val['province_id']);
            $val['city_id'] = DistrictArr::getDistrictName($val['city_id']);
            $val['district_id'] = DistrictArr::getDistrictName($val['district_id']);
        }

        $data = [
            'count' => $count,
            'mch_list'  => $list,
            'page' => $page,
            'page_count'=>$pagination->pageCount,
            'pagination'=>$pagination,
        ];
        $data['page_url'] = \Yii::$app->urlManager->createUrl(['mch_api/diy/diy/get-mch-list']);
        $data['page_url'] .= sprintf('?access_token=%s&store_id=%d', $this->accessToken, $this->store->id);
        //$data['page_url'] .= sprintf('?access_token=%s&store_id=%d&mch_token=%d', $this->accessToken, $this->store->id, $this->mchToken);
        return new ApiResponse(200, 'success', $data);
    }

    /**
     * 获取论坛信息列表
     */
    public function actionBbsList()
    {
        $request = \Yii::$app->request;
        $title = $request->get('title', '');
        //add by wuyh http://cd.jvtd.cn/bug-view-50550.html
        //在首页获取模板配置的时候，需要过滤（pay_checked=1）经过了支付检查的信息数据
        $pay_checked = $request->get('pay_checked', '');
        //概况分析
        $query = BbsArticle::find()->alias('b')
            ->leftJoin(['u' => User::tableName()], 'b.user_id = u.id')
            ->where(['b.store_id' => $this->store->id,'b.is_display'=>1,'b.status'=>1])
            ->andWhere('deleted_at is null');
        if ($title != '') {
            $query->andWhere([
                'or',
                ['like', 'u.nickname', $title],
                ['like', 'b.mobile', $title],
                ['like', 'b.title', $title]
            ]);
        }
        if($pay_checked == '1'){
            $query->andWhere(['pay_checked'=>1]);
        }
        $total = $query->count();
        $pageSize = \Yii::$app->request->get('page_size') ?? 20;
        $pagination = new Pagination(['totalCount' => $total, 'pageSize' => $pageSize]);
        $like_count = BbsArticleReader::find()->where('article_id = b.id and type = 2')->select('count(1)');//点赞总数
        $share_count = BbsComment::find()->where('article_id = b.id and status = 1')->select('count(1)');//留言总数
        $data = $query->offset($pagination->offset)->limit($pagination->limit)
            ->select(['u.nickname','u.avatar_url','b.mobile','b.title','b.images', 'b.view_count','b.created_at','b.is_display','b.id','b.content','b.address','like_count'=>$like_count,'share_count'=>$share_count])
            ->orderBy('b.id desc')
            ->asArray()->all();
        $result['total'] = $total;
        $result['list'] = $data;
        return [
            'code' => 200,
            'msg' => 'success',
            'data' => $result
        ];
    }

    /**
     * 获取商品分组列表
     * @return ApiResponse
     */
    public function actionCatList()
    {
        $catList = (new CatForm())->getCatList();
        return new ApiResponse(200, "Success", $catList);
    }

    /**
     * 拼团新版商品列表
     * @return ApiResponse
     */
    public function actionGroupList(){
        $form = new GroupActivityForm();
        $form->store_id = $this->store->id;
        $form->keyword = (string)\Yii::$app->request->get('keyword');
        $form->cat = (int)\Yii::$app->request->get('cat');
        $form->page_size = (int)\Yii::$app->request->get('page_size',10);
        $form->page = (int)\Yii::$app->request->get('page',1);
        $goodsList = $form->goodsListDiy();
        return new ApiResponse(200, "Success", $goodsList);
    }

    /**
     * 积分商城商品列表
     * @return ApiResponse
     */
    public function actionIntegralList(){
        $form = new IntegralGoodsForm();
        $form->store_id = $this->store->id;
        $form->keyword = (string)\Yii::$app->request->get('keyword');
        $form->cat = (int)\Yii::$app->request->get('cat');
        $form->page_size = (int)\Yii::$app->request->get('page_size',10);
        $form->page = (int)\Yii::$app->request->get('page',1);
        $goodsList = $form->goodsListDiy();
        return new ApiResponse(200, "Success", $goodsList);
    }

    /**
     * 砍价新版商品列表
     * @return ApiResponse
     */
    public function actionBargainList(){
        $form = new BargainForm();
        $form->store_id = $this->store->id;
        $form->keyword = (string)\Yii::$app->request->get('keyword');
        $form->cat = (int)\Yii::$app->request->get('cat');
        $form->page_size = (int)\Yii::$app->request->get('page_size',10);
        $form->page = (int)\Yii::$app->request->get('page',1);
        $goodsList = $form->goodsListDiy();
        return new ApiResponse(200, "Success", $goodsList);
    }
}