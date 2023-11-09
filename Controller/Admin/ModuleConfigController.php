<?php

/*
 * This file is part of the Thelia package.
 * http://www.thelia.net
 *
 * (c) OpenStudio <info@thelia.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace RewriteUrl\Controller\Admin;

use Propel\Runtime\ActiveQuery\Criteria;
use RewriteUrl\Model\RewriteurlRule;
use RewriteUrl\Model\RewriteurlRuleParam;
use RewriteUrl\Model\RewriteurlRuleQuery;
use RewriteUrl\RewriteUrl;
use Thelia\Controller\Admin\BaseAdminController;
use Thelia\Core\HttpFoundation\JsonResponse;
use Thelia\Core\HttpFoundation\Request;
use Thelia\Core\HttpFoundation\Response;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Core\Translation\Translator;
use Thelia\Exception\TheliaProcessException;
use Thelia\Model\ConfigQuery;

class ModuleConfigController extends BaseAdminController
{
    public function viewConfigAction()
    {
        if (null !== $response = $this->checkAuth([AdminResources::MODULE], 'RewriteUrl', AccessManager::VIEW)) {
            return $response;
        }

        $isRewritingEnabled = ConfigQuery::isRewritingEnable();

        return $this->render(
            'RewriteUrl/module-configuration',
            [
                'isRewritingEnabled' => $isRewritingEnabled,
            ]
        );
    }

    public function getDatatableRules(Request $request)
    {
        $requestSearchValue = $request->get('search') ? '%'.$request->get('search')['value'].'%' : '';
        $recordsTotal = RewriteurlRuleQuery::create()->count();
        $search = RewriteurlRuleQuery::create();
        if ('' !== $requestSearchValue) {
            $search
                ->filterByValue($requestSearchValue, Criteria::LIKE)
                ->_or()
                ->filterByRedirectUrl($requestSearchValue)
            ;
        }

        $recordsFiltered = $search->count();

        $orderColumn = $request->get('order')[0]['column'];
        $orderDirection = $request->get('order')[0]['dir'];
        switch ($orderColumn) {
            case '0':
                $search->orderByRuleType($orderDirection);
                break;
            case '1':
                $search->orderByValue($orderDirection);
                break;
            case '2':
                $search->orderByOnly404($orderDirection);
                break;
            case '3':
                $search->orderByRedirectUrl($orderDirection);
                break;
            case '4':
                $search->orderByPosition($orderDirection);
                break;
            default:
                $search->orderByPosition();
                break;
        }

        $search
            ->offset($request->get('start'))
            ->limit($request->get('length'))
        ;
        $searchArray = $search->find()->toArray();

        $resultsArray = [];
        foreach ($searchArray as $row) {
            $id = $row['Id'];
            $isRegexSelected = $row['RuleType'] === RewriteurlRule::TYPE_REGEX ? 'selected' : '';
            $isParamsSelected = $row['RuleType'] === RewriteurlRule::TYPE_GET_PARAMS ? 'selected' : '';
            $isRegexParamsSelected = $row['RuleType'] === RewriteurlRule::TYPE_REGEX_GET_PARAMS ? 'selected' : '';
            $isOnly404Checked = $row['Only404'] ? 'checked' : '';
            $rewriteUrlRuleParams = RewriteurlRuleQuery::create()->findPk($row['Id'])->getRewriteUrlParamCollection();
            $resultsArray[] = [
                'Id' => $row['Id'],
                'RuleType' => '<select class="js_rule_type form-control" data-idrule="'.$id.'" required>
                                <option value="'.RewriteurlRule::TYPE_REGEX.'" '.$isRegexSelected.'>'.Translator::getInstance()->trans('Regex', [], RewriteUrl::MODULE_DOMAIN).'</option>
                                <option value="'.RewriteurlRule::TYPE_GET_PARAMS.'" '.$isParamsSelected.'>'.Translator::getInstance()->trans('Get Params', [], RewriteUrl::MODULE_DOMAIN).'</option>
                                <option value="'.RewriteurlRule::TYPE_REGEX_GET_PARAMS.'" '.$isRegexParamsSelected.'>'.Translator::getInstance()->trans('Regex and Get Params', [], RewriteUrl::MODULE_DOMAIN).'</option>
                               </select>',
                'Value' => $this->renderRaw(
                    'RewriteUrl/tab-value-render',
                    [
                        'ID' => $row['Id'],
                        'REWRITE_URL_PARAMS' => $rewriteUrlRuleParams,
                        'VALUE' => $row['Value'],
                    ]
                ),
                'Only404' => '<input class="js_only404 form-control" type="checkbox" style="width: 100%!important;" '.$isOnly404Checked.'/>',
                'RedirectUrl' => '<div class="col-md-12 input-group">
                                    <input class="js_url_to_redirect form-control" type="text" placeholder="/path/mypage.html" value="'.$row['RedirectUrl'].'"/>
                                  </div>',
                'Position' => '<a href="#" class="u-position-up js_move_rule_position_up" data-idrule="'.$id.'"><i class="glyphicon glyphicon-arrow-up"></i></a>
                                <span class="js_editable_rule_position editable editable-click" data-idrule="'.$id.'">'.$row['Position'].'</span>
                               <a href="#" class="u-position-down js_move_rule_position_down" data-idrule="'.$id.'"><i class="glyphicon glyphicon-arrow-down"></i></a>',
                'Actions' => '<a href="#" class="js_btn_update_rule btn btn-success" data-idrule="'.$id.'"><span class="glyphicon glyphicon-check"></span></a>
                              <a href="#" class="js_btn_remove_rule btn btn-danger" data-idrule="'.$id.'"><span class="glyphicon glyphicon-remove"></span></a>
',
            ];
        }

        return new JsonResponse([
            'draw' => $request->get('draw'),
            'recordsTotal' => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data' => $resultsArray,
        ]);
    }

    public function setRewritingEnableAction(Request $request): Response
    {
        $isRewritingEnable = $request->get('rewriting_enable', null);

        if ($isRewritingEnable !== null) {
            ConfigQuery::write('rewriting_enable', $isRewritingEnable ? 1 : 0);

            return $this->jsonResponse(json_encode(['state' => 'Success']), 200);
        }

        return $this->jsonResponse(Translator::getInstance()->trans(
            'Unable to change the configuration variable.',
            [],
            RewriteUrl::MODULE_DOMAIN
        ), 500);
    }

    public function addRuleAction(Request $request)
    {
        try {
            $rule = new RewriteurlRule();

            $this->fillRuleObjectFields($rule, $request);
        } catch (\Exception $ex) {
            return $this->jsonResponse($ex->getMessage(), 500);
        }

        return $this->jsonResponse(json_encode(['state' => 'Success']), 200);
    }

    public function updateRuleAction(Request $request)
    {
        try {
            $rule = RewriteurlRuleQuery::create()->findOneById($request->get('id'));

            if ($rule === null) {
                throw new \Exception(Translator::getInstance()->trans(
                    'Unable to find rule to update.',
                    [],
                    RewriteUrl::MODULE_DOMAIN
                ));
            }

            $this->fillRuleObjectFields($rule, $request);
        } catch (\Exception $ex) {
            return $this->jsonResponse($ex->getMessage(), 500);
        }

        return $this->jsonResponse(json_encode(['state' => 'Success']), 200);
    }

    public function removeRuleAction(Request $request)
    {
        try {
            $rule = RewriteurlRuleQuery::create()->findOneById($request->get('id'));

            if ($rule === null) {
                throw new \Exception(Translator::getInstance()->trans(
                    'Unable to find rule to remove.',
                    [],
                    RewriteUrl::MODULE_DOMAIN
                ));
            }

            $rule->delete();
        } catch (\Exception $ex) {
            return $this->jsonResponse($ex->getMessage(), 500);
        }

        return $this->jsonResponse(json_encode(['state' => 'Success']), 200);
    }

    public function moveRulePositionAction(Request $request)
    {
        try {
            $rule = RewriteurlRuleQuery::create()->findOneById($request->get('id'));

            if ($rule === null) {
                throw new \Exception(Translator::getInstance()->trans(
                    'Unable to find rule to change position.',
                    [],
                    RewriteUrl::MODULE_DOMAIN
                ));
            }

            $type = $request->get('type', null);

            if ($type === 'up') {
                $rule->movePositionUp();
            } elseif ($type === 'down') {
                $rule->movePositionDown();
            } elseif ($type === 'absolute') {
                $position = $request->get('position', null);
                if (!empty($position)) {
                    $rule->changeAbsolutePosition($position);
                }
            }
        } catch (\Exception $ex) {
            return $this->jsonResponse($ex->getMessage(), 500);
        }

        return $this->jsonResponse(json_encode(['state' => 'Success']), 200);
    }

    /**
     * @throws \Propel\Runtime\Exception\PropelException
     */
    protected function fillRuleObjectFields(RewriteurlRule $rule, Request $request): void
    {
        $ruleType = $request->get('ruleType', null);

        $isParamRule = $ruleType === RewriteurlRule::TYPE_GET_PARAMS || $ruleType === RewriteurlRule::TYPE_REGEX_GET_PARAMS;
        $isRegexRule = $ruleType === RewriteurlRule::TYPE_REGEX || $ruleType === RewriteurlRule::TYPE_REGEX_GET_PARAMS;

        if (!($isParamRule || $isRegexRule)) {
            throw new TheliaProcessException(Translator::getInstance()->trans('Unknown rule type.', [], RewriteUrl::MODULE_DOMAIN));
        }

        $regexValue = $request->get('value', null);

        if ($isRegexRule && empty($regexValue)) {
            throw new TheliaProcessException(Translator::getInstance()->trans('Regex value cannot be empty.', [], RewriteUrl::MODULE_DOMAIN));
        }

        $redirectUrl = $request->get('redirectUrl', null);

        if (empty($redirectUrl)) {
            throw new TheliaProcessException(Translator::getInstance()->trans('Redirect url cannot be empty.', [], RewriteUrl::MODULE_DOMAIN));
        }

        $paramRuleArray = [];

        if ($isParamRule) {
            $paramRuleArray = $request->get('paramRules', null);
            if (empty($paramRuleArray)) {
                throw new TheliaProcessException(Translator::getInstance()->trans('At least one GET parameter is required.', [], RewriteUrl::MODULE_DOMAIN));
            }
        }

        $rule
            ->setRuleType($ruleType)
            ->setValue($regexValue)
            ->setOnly404($request->get('only404', 1))
            ->setRedirectUrl($redirectUrl)
        ;

        if (empty($rule->getPosition())) {
            $rule->setPosition($rule->getNextPosition());
        }

        $rule->deleteAllRelatedParam();

        $rule->save();

        if ($isParamRule) {
            foreach ($paramRuleArray as $paramRule) {
                if (!\array_key_exists('paramName', $paramRule) || empty($paramRule['paramName'])) {
                    throw new TheliaProcessException(Translator::getInstance()->trans(
                        'Param name is empty.',
                        [],
                        RewriteUrl::MODULE_DOMAIN
                    ));
                }
                if (!\array_key_exists('condition', $paramRule) || empty($paramRule['condition'])) {
                    throw new TheliaProcessException(Translator::getInstance()->trans(
                        'Param condition is empty.',
                        [],
                        RewriteUrl::MODULE_DOMAIN
                    ));
                }

                (new RewriteurlRuleParam())
                    ->setParamName($paramRule['paramName'])
                    ->setParamCondition($paramRule['condition'])
                    ->setParamValue($paramRule['paramValue'])
                    ->setIdRule($rule->getId())
                    ->save();
            }
        }
    }
}
