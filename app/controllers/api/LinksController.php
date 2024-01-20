<?php
/**
 * =======================================================================================
 *                           GemFramework (c) GemPixel                                     
 * ---------------------------------------------------------------------------------------
 *  This software is packaged with an exclusive framework as such distribution
 *  or modification of this framework is not allowed before prior consent from
 *  GemPixel. If you find that this framework is packaged in a software not distributed 
 *  by GemPixel or authorized parties, you must not use this software and contact GemPixel
 *  at https://gempixel.com/contact to inform them of this misuse.
 * =======================================================================================
 *
 * @package GemPixel\Premium-URL-Shortener
 * @author GemPixel (https://gempixel.com) 
 * @license https://gempixel.com/licenses
 * @link https://gempixel.com  
 */

namespace API;

use \Core\Helper;
use \Core\Request;
use \Core\Response;
use \Core\DB;
use \Core\Auth;
use \Models\User;

class Links {

    use \Traits\Links;
    /**
     * List All Links
     *
     * @author GemPixel <https://gempixel.com> 
     * @version 7.2.2
     * @return void
     */
    public function get(Request $request){
        
        $user = Auth::ApiUser();

        $urls = [];

        $query = DB::url()->where('userid', $user->id)->whereNull('qrid')->whereNull('profileid');

        $page = (int) currentpage();

        if($request->short){
            
            if(!Helper::isURL($request->short)) return Response::factory(['error' => 1, 'message' => 'Invalid short url. Please try again.'])->json();
            
            $parts = explode('/', $request->short);

            if($url = $this->getURL($request, end($parts))){
                
                if($url->userid !== $user->id) return Response::factory(['error' => 1, 'message' => 'You do not have the permission to access this short url.'])->json();
                
                if($url->profileid || $url->qrid) return Response::factory(['error' => 1, 'message' => 'Invalid short url. Please try again.'])->json();

                return $this->single($url->id);

            } else {
                return Response::factory(['error' => 1, 'message' => 'Invalid short url. Please try again.'])->json();
            }
        }

        $limit = 15;

        if( $request->limit && \is_numeric($request->limit) ){                    
            $limit = (int) $request->limit;
        } 

        $total = $query->count();

        if($request->order && $request->order == "click"){

            $query->orderByDesc('click');

        } else{
            $query->orderByDesc('date');
        }

        $results = $query->limit($limit)->offset(($page-1)*$limit)->findMany();
        
        if(($total % $limit)<>0) {
            $max = floor($total/$limit)+1;
        } else {
            $max = floor($total/$limit);
        }  
    
        foreach($results as $url){

            $urls[] = [
                "id" => (int) $url->id,
                "alias" => $url->alias.$url->custom,
                "shorturl" => \Helpers\App::shortRoute($url->domain, $url->alias.$url->custom),
                "longurl" => $url->url,
                "title" => $url->meta_title,
                "description" => $url->meta_description,
                "clicks" => $url->click,
                "uniqueclicks" => $url->uniqueclick,
                "date" => $url->date
            ];
        }

        return Response::factory(['error' => 0, 'data' => ['result' => $total, 'perpage' => $limit, 'currentpage' => $page, 'nextpage' => $max == 0 || $page == $max ? null : $page+1, 'maxpage' => $max, 'urls' => $urls]])->json();


    }
    /**
     * Get a single link
     *
     * @author GemPixel <https://gempixel.com> 
     * @version 6.0
     * @param integer $id
     * @return void
     */
    public function single(int $id){
        
        $user = Auth::ApiUser();

        if(!$url = DB::url()->where('userid', $user->id)->where('id', $id)->first()){
            return Response::factory(['error' => 1, 'message' => 'Link does not exist.'])->json();
        }

        $result = [
            "id" => (int) $url->id,
            "alias" => $url->alias.$url->custom,
            "shorturl" => \Helpers\App::shortRoute($url->domain, $url->alias.$url->custom),
            "longurl" => $url->url,
            "title" => $url->meta_title,
            "description" => $url->meta_description,
            "location" => json_decode($url->location),
            "device" => json_decode($url->device),
            "expiry" => $url->expiry,
            "date" => $url->date
        ];

        $stats = [
            "clicks" => (int) $url->click,
            "uniqueClicks" => (int) $url->uniqueclick
        ];


        $countries = DB::stats()
                        ->selectExpr('COUNT(id)', 'count')
                        ->selectExpr('country', 'country')
                        ->where('urlid', $url->id)
                        ->groupByExpr('country')
                        ->orderByDesc('count')
                        ->limit(10)
                        ->findArray();
    
        foreach ($countries as $country) {

            if(empty($country['country'])) $country['country'] = 'unknown';

            $stats['topCountries'][ucwords($country['country'])] = (int) $country['count'];
        }
        
        $browsers = DB::stats()
                    ->selectExpr('COUNT(id)', 'count')
                    ->selectExpr('browser', 'browser')
                    ->where('urlid', $url->id)
                    ->groupByExpr('browser')
                    ->orderByDesc('count')
                    ->limit(10)
                    ->findArray();
        
        foreach ($browsers as $browser) {
            $stats['topBrowsers'][ucwords($browser['browser'])] = (int) $browser['count'];
        }

        $oss = DB::stats()
                    ->selectExpr('COUNT(id)', 'count')
                    ->selectExpr('os', 'os')
                    ->where('urlid', $url->id)
                    ->groupByExpr('os')
                    ->orderByDesc('count')
                    ->limit(10)
                    ->findArray();
        
        foreach ($oss as $os) {
            $stats['topOs'][ucwords($os['os'])] = (int) $os['count'];
        }

        $referrers = DB::stats()
                        ->select('domain', 'referer')
                        ->selectExpr('COUNT(domain)', 'count')
                        ->where('urlid', $url->id)
                        ->groupBy('domain')
                        ->orderByDesc('count')
                        ->limit(10)
                        ->findArray();
    
        foreach ($referrers as $referrer) {
            
            if(empty($referrer['domain'])) $referrer['domain'] = e("Direct, email and other");

            if(!preg_match("~facebook.~", $referrer['domain']) && 
                !preg_match("~fb.~", $referrer['domain']) && 
                    !preg_match("~t.co~", $referrer['domain']) && 
                        !preg_match("~twitter.~", $referrer['domain']) && 
                            !preg_match("~instagram.~", $referrer['domain'])){       

                $stats['topReferrers'][$referrer['domain']] = $referrer['count'];
            }
        }  

        $stats['socialCount']['facebook'] = DB::stats()->where("urlid", $url->id)->whereRaw("(domain LIKE '%facebook.%' OR domain LIKE '%fb.%')")->count();
        $stats['socialCount']['twitter'] = DB::stats()->where("urlid", $url->id)->whereRaw("(domain LIKE '%twitter.%' OR domain LIKE '%t.co%')")->count();
        $stats['socialCount']['instagram']  = DB::stats()->where("urlid", $url->id)->whereRaw("(domain LIKE '%instagram.%')")->count();
        $stats['socialCount']['linkedin']  = DB::stats()->where("urlid", $url->id)->whereRaw("(domain LIKE '%linkedin.%')")->count();
    
        return Response::factory(['error' => 0, 'id' => $url->id, 'details' => $result, 'data' => $stats])->json();
    }
    /**
     * Shorten a link
     *
     * @author GemPixel <https://gempixel.com> 
     * @version 6.0
     * @param \Core\Request $request
     * @return void
     */
    public function create(Request $request){

        $user = Auth::ApiUser();

        $count = DB::url()->where('userid', $user->rID())->count();

        $total = $user->plan('numurls');

        if($total && $total > 0){
            if($user->plan('counttype') == 'monthly'){

                $firstday = date('Y-m-01');

                $lastday = date('Y-m-t');

                $count = DB::url()->whereRaw("(date BETWEEN '{$firstday}' AND '{$lastday}') AND userid = ?", $user->rID())->count();

                if($count >= $total) throw new \Exception(e('You have reached your monthly limit. Please upgrade to another plan.'));

            } else {
                if($count >= $total) throw new \Exception(e('You have reached your maximum links limit. Please upgrade to another plan.'));
            }
        }
        
        $data = $request->getJSON();

        $link = new \stdClass;

        if(!isset($data->url)){
            return Response::factory(['error' => 1, 'message' => 'Missing required parameter: url'])->json();
        }

        if(isset($data->url) && !empty($data->url)) $link->url = Helper::clean($data->url, 3);

        $link->custom = (isset($data->custom) && !empty($data->custom)) ? clean($data->custom) : null;

		$link->pass = (isset($data->password) && !empty($data->password)) ? clean($data->password) : null;

		$link->domain = (isset($data->domain) && !empty($data->domain)) ? clean($data->domain) : null;

		$link->expiry = (isset($data->expiry) && !empty($data->expiry)) ? clean($data->expiry) : null;

		$link->metatitle = (isset($data->metatitle) && !empty($data->metatitle)) ? clean($data->metatitle) : null;

		$link->metadescription = (isset($data->metadescription) && !empty($data->metadescription)) ? clean($data->metadescription) : null;

        $link->type = null;
        $link->location = null;
        $link->device = null;
        $link->state = null;
        $link->language = null;
        $link->paramname  = null;
        $link->paramvalue  = null;
        $link->metaimage = null;
        $link->custommetaimage = null;
        $link->description = null;
        $link->pixels = null;
        $link->abtesting = null;
        $link->clicklimit = null;
        $link->expirationredirect = null;
        $link->channel = (isset($data->channel) && !empty($data->channel)) ? clean($data->channel) : null;
        $link->deeplink = null;
        
        if(isset($data->metaimage)){
            
            $extension = Helper::extension($data->metaimage);

            if(in_array($extension, ['jpg', 'jpeg', 'png'])){         
                $name = Helper::rand(6)."_ai_".Helper::rand(6).".".$extension; 
                
                $image = appConfig('app.storage')['images']['path'].'/'.$name;

                $imagedata = getimagesize($image);
                
                copy($data->metaimage, $image);

                if(config('cdn') && config('cdn')->enabled){
                    \Helpers\CDN::factory()->upload(str_replace(PUB.'/', '', $image), $image, $imagedata['mime']);
                    unlink($image);
                }

                if(is_array($imagedata) && in_array($imagedata['mime'], ['image/jpg', 'image/jpeg', 'image/png'])) {
                    $link->custommetaimage = $name;
                } else {
                    \Helpers\App::delete($image);
                    return Response::factory(['error' => 0, 'message' => e('Banner must be either a PNG or a JPEG (Max 500kb).')])->json();
                }
            }
        }

        if(isset($data->type)){
            $link->type = clean($data->type);
		}

        if(isset($data->geotarget)){
			foreach ($data->geotarget as $country ) {
				$link->location[] = $country->location;
				$link->target[] = $country->link;
			}
		}

		if(isset($data->devicetarget)){
			foreach ($data->devicetarget as $device ) {
				$link->device[] = $device->device;
				$link->dtarget[] = $device->link;
			}
		}

        if(isset($data->languagetarget)){
			foreach ($data->languagetarget as $language ) {
				$link->language[] = substr($language->language, 0, 2);
				$link->ltarget[] = $language->link;
			}
		}
                
        if(isset($data->parameters)){
			foreach ($data->parameters as $param ) {
				$link->paramname[] = $param->name;
				$link->paramvalue[] = $param->value;
			}
		}

        if(isset($data->pixels)){
            foreach($data->pixels as $pixelid){
                if($pixel = DB::pixels()->where('id', $pixelid)->where('userid', $user->id)->first()){
                    $link->pixels[] = $pixel->type.'-'.$pixel->id;
                }
            }
        }

        if(isset($data->deeplink)){
            $link->deeplink['enabled'] = true;
            $link->deeplink['apple'] = $data->deeplink->apple;
            $link->deeplink['google'] = $data->deeplink->google;
        }

        if(!$link->domain && $user->domain){
            $link->domain = $user->domain;
        }

        try{
            
            $result = $this->createLink($link, $user);

            return Response::factory(['error' => 0, 'id' => $result['id'], 'shorturl' => $result['shorturl']])->json();

        } catch(\Exception $e){

            return Response::factory(['error' => 1, 'message' => $e->getMessage()])->json();

        }

    }
    /**
     * Update a link
     *
     * @author GemPixel <https://gempixel.com> 
     * @version 6.0
     * @param \Core\Request $request
     * @param integer $id
     * @return void
     */
    public function update(Request $request, int $id){

        $user = Auth::ApiUser();
        
        $data = $request->getJSON();

        if(!$url = DB::url()->where('userid', $user->id)->where('id', $id)->first()){
            return Response::factory(['error' => 1, 'message' => 'Link does not exist.'])->json();
        }

        $link = new \stdClass;

        $link->type = null;
        $link->location = null;
        $link->device = null;
        $link->language = null;
        $link->state = null;
        $link->paramname  = null;
        $link->paramvalue  = null;
        $link->metatitle = null;
        $link->metadescription = null;
        $link->metaimage = null;
        $link->description = null;
        $link->abtesting = null;
        $link->clicklimit = null;
        $link->expirationredirect = null;
        $link->deeplink = null;

        $link->url = isset($data->url) && !empty($data->url) ? clean($data->url) : null;

        $link->custom = (isset($data->custom) && !empty($data->custom)) ? clean($data->custom) : null;

		$link->pass = (isset($data->password) && !empty($data->password)) ? clean($data->password) : null;

		$link->domain = (isset($data->domain) && !empty($data->domain)) ? clean($data->domain) : null;

		$link->expiry = (isset($data->expiry) && !empty($data->expiry)) ? clean($data->expiry) : null;

        $link->metatitle = (isset($data->metatitle) && !empty($data->metatitle)) ? clean($data->metatitle) : null;
        
		$link->metadescription = (isset($data->metadescription) && !empty($data->metadescription)) ? clean($data->metadescription) : null;

        $link->pixels = null;

        $link->channel = (isset($data->channel) && !empty($data->channel)) ? clean($data->channel) : null;;

        // Custom Image
        if(isset($data->metaimage)){
            
            $extension = Helper::extension($data->metaimage);

            if(in_array($extension, ['jpg', 'jpeg', 'png'])){         
                $name = Helper::rand(6)."_ai_".Helper::rand(6).".".$extension; 
                
                $image = appConfig('app.storage')['images']['path'].'/'.$name;

                copy($data->metaimage, $image);
                $imagedata = getimagesize($image);

                if(config('cdn') && config('cdn')->enabled){
                    \Helpers\CDN::factory()->upload(str_replace(PUB.'/', '', $image), $image, $imagedata['mime']);
                    unlink($image);
                }

                if(is_array($imagedata) && in_array($imagedata['mime'], ['image/jpg', 'image/jpeg', 'image/png'])) {
                    $link->custommetaimage = $name;
                } else {
                    \Helpers\App::delete($image);
                    return Response::factory(['error' => 0, 'message' => e('Banner must be either a PNG or a JPEG (Max 500kb).')])->json();
                }
            }
        }

        if(isset($data->type)){
            $link->type = clean($data->type);
		}

        if(isset($data->geotarget)){
			foreach ($data->geotarget as $country ) {
				$link->location[] = $country->location;
				$link->target[] = $country->link;
			}
		}

		if(isset($data->devicetarget)){
			foreach ($data->devicetarget as $device ) {
				$link->device[] = $device->device;
				$link->dtarget[] = $device->link;
			}
		}

        if(isset($data->languagetarget)){
			foreach ($data->languagetarget as $language ) {
				$link->language[] = substr($language->language, 0, 2);
				$link->ltarget[] = $language->link;
			}
		}        

        if(isset($data->parameters)){
			foreach ($data->parameters as $param ) {
				$link->paramname[] = $param->name;
				$link->paramvalue[] = $param->value;
			}
		}

        if(isset($data->pixels)){
            foreach($data->pixels as $pixelid){
                if($pixel = DB::pixels()->where('id', $pixelid)->where('userid', $user->id)->first()){
                    $link->pixels[] = $pixel->type.'-'.$pixel->id;
                }
            }
        }

        if(isset($data->deeplink)){
            $link->deeplink['enabled'] = true;
            $link->deeplink['apple'] = $data->deeplink->apple;
            $link->deeplink['google'] = $data->deeplink->google;
        }

        try{
            
            $result = $this->updateLink($link, $url, $user);

            return Response::factory(['error' => 0, 'id' => $result['id'], 'shorturl' => $result['shorturl']])->json();

        } catch(\Exception $e){

            return Response::factory(['error' => 1, 'message' => $e->getMessage()])->json();

        }

    }
    /**
     * Delete Link
     *
     * @author GemPixel <https://gempixel.com> 
     * @version 6.0
     * @param integer $id
     * @return void
     */
    public function delete(int $id){

        $user = Auth::ApiUser();

        if(!$url = DB::url()->where('userid', $user->id)->where('id', $id)->first()) return Response::factory(['error' => 1, 'message' => 'Link does not exist.'])->json();

        if($this->deleteLink($id, Auth::ApiUser())){
            return Response::factory(['error' => 0, 'message' => 'Link has been successfully deleted.'])->json();
        }
        return Response::factory(['error' => 1, 'message' => 'An error occurred and the link was not deleted.'])->json();
    }    
}