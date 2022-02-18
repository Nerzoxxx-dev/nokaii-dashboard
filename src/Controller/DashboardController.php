<?php

namespace App\Controller;

use App\Utils\Http;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class DashboardController extends AbstractController {
  const ENDPOINT_URL = "https://discord.com/api/v9/oauth2/";
  const USER_ENDPOINT_URL = "https://discord.com/api/v9/users/@me";

  private array $guilds = [];
  private HttpClientInterface $httpClient;
  private RequestStack $requestStack;

  public function __construct(
    HttpClientInterface $httpClient,
    RequestStack $requestStack
  ){
    $this->httpClient = $httpClient;
    $this->requestStack = $requestStack;
  }
  /**
   * @Route("/login", name="login", methods="GET")
   */
  public function login(
    UrlGeneratorInterface $urlGenerator
  ){
    $redirectUrl = $urlGenerator->generate('oauth2', [], UrlGenerator::ABSOLUTE_URL);
    $queryParameters = http_build_query([
      'client_id' => $this->getParameter('app.client_id'),
      'prompt' => 'consent',
      'redirectUri' => $redirectUrl,
      'response_type' => 'code',
      'scope' => 'identify email guilds',
     ]);

     return new RedirectResponse(self::ENDPOINT_URL . 'authorize?' . $queryParameters);
  }

  /**
   * @Route("/oauth2", name="oauth2", methods="GET")
   */
  public function oauth2(
    Request $request
  ){
    if(!$request->query->has('code')) { return $this->redirectToRoute("login"); }
    $code = $this->getAccessToken($request->query->get('code'));
    $this->requestStack->getSession()->set('access_token', $code['access_token']);
    setcookie('access_token_expires_at', time() + ($code['expires_in'] / 1000));
    setcookie('refresh_token', $code['refresh_token']);
    return $this->redirectToRoute('viewGuilds');
  }

  public function getAccessToken(
    string $code,
  ){
    $opts = [
      "body" => [
        "client_id" => $this->getParameter('app.client_id'),
        "client_secret" => $this->getParameter('app.client_secret'),
        "grant_type" => "authorization_code",
        "code" => $code,
        "scope" => "bot"
      ],
      "headers" => [
        'Accept' => 'application/json',
        'Content-Type' => "application/x-www-form-urlencoded"
      ]
    ];
    $r = $this->httpClient->request('POST', self::ENDPOINT_URL . 'token', $opts);
    $r = $r->toArray();
    if(!$r['access_token']) throw new ServiceUnavailableHttpException(null, "La connexion avec Discord a échoué, veuillez réssayer.");
    //dd($r);
    //$this->guilds = $r['guild'];
    return $r;
  }

  public function checkRefreshToken(){
    if($_COOKIE['access_token_expires_at'] - time() <= 0){
      if(!isset($_COOKIE['refresh_token'])) $this->redirectToRoute('login');
      $opts = [
        "headers" => [
          'Content-Type' => 'application/x-www-form-urlencoded',
          'Accept' => 'application/json'
        ],
        "body" => [
          "client_id" => $this->getParameter('app.client_id'),
          "client_secret" => $this->getParameter('app.client_secret'),
          'grant_type' => 'refresh_token',
          'refresh_token' => $_COOKIE['refresh_token'],
          'scope' => 'bot'
        ]
      ];
      $r = $this->httpClient->request('POST', self::ENDPOINT_URL . 'token', $opts);
      $r = $r->toArray();
      if(!$r['access_token']) throw new ServiceUnavailableHttpException(null, 'La connexion avec Discord a échoué, veuillez réessayer.');
      //$this->guilds = $r['guild'];
      $this->requestStack->getSession()->set('access_token', $r['access_token']);
      $res = new Response();
      $_COOKIE["access_token_expires_at"] = $r['expires_in'];
      $_COOKIE["refresh_token"] = $r['refresh_token'];
      $res->send();
    }
  }

  public function getUserData(
    string $accessToken
  ){

    $opts = [
      'headers' => [
          'Authorization' => "Bearer {$accessToken}",
          'Accept' => 'application/json'
        ]
      ];

      $response = $this->httpClient->request('GET', self::USER_ENDPOINT_URL, $opts);
      $response = $response->toArray();

      if(!$response['username'] || !$response['email'] || !$response['id']){
        throw new ServiceUnavailableHttpException(null, 'Les crédits ne peuvent pas être trouvés.');
      }

      return $response;
  }

  public function getGuilds(){
    return $this->guilds;
  }
  /**
   * @Route("/guilds", name="viewGuilds", methods="GET")
   */
  public function viewGuilds(){
    $this->checkRefreshToken();
    //dd($_COOKIE, $this->requestStack->getSession()->all(), $_COOKIE['access_token_expires_at'] - time(),$this->getGuilds());
    return $this->render('base.html.twig');
  }
}