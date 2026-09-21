@extends('layouts.base')

@section('title')
<title>隱私權政策 - Amanda 的部落格</title>
@endsection

@section('meta')
<meta name="description" content="Amanda 的部落格隱私權政策：瀏覽紀錄、Cookie、Google 廣告與隱私設定。">
@endsection

@section('static_content')
<main class="container my-5">
    <article class="col-lg-8 mx-auto">
        <h1 class="h2 mb-3">隱私權政策</h1>
        <p class="text-secondary">最後更新：2026 年 9 月 21 日</p>
        <p>本政策說明 Amanda 的部落格（amanda-blog.com）在提供文章閱讀、網站統計與廣告服務時，如何使用資料與 Cookie。</p>

        <h2 class="h4 mt-4">瀏覽紀錄與網站功能</h2>
        <p>本站會記錄瀏覽者的 IP 位址及造訪日期，用於網站瀏覽統計。網站伺服器也可能記錄請求網址、瀏覽器資訊與存取時間，以維護網站運作及排除問題。</p>
        <p>本站使用工作階段及安全性 Cookie，並可能以 Cookie 記住密碼保護文章的驗證狀態。本站亦使用 Google Tag Manager 管理網站標籤；透過標籤載入的服務可能依其功能處理瀏覽資料。</p>

        <h2 class="h4 mt-4">Google AdSense 與第三方廣告</h2>
        <p>本站可能透過 Google AdSense 顯示廣告。啟用廣告時，Google 及其他第三方供應商或廣告聯播網可能使用 Cookie，依據您先前造訪本站或其他網站的紀錄放送廣告。Google 與合作夥伴也可能利用廣告 Cookie 提供個人化廣告、衡量廣告成效及防止濫用。</p>
        <p>您可以前往 <a href="https://myadcenter.google.com/">Google 我的廣告中心</a>管理或停用個人化廣告，也可以透過 <a href="https://optout.aboutads.info/">Digital Advertising Alliance 的退出工具</a>管理參與供應商的個人化廣告選項。</p>
        <p>相關服務與供應商資訊請參閱 <a href="https://policies.google.com/privacy?hl=zh-TW">Google 隱私權政策</a>、<a href="https://policies.google.com/technologies/partner-sites?hl=zh-TW">Google 如何使用合作夥伴網站的資料</a>及 <a href="https://support.google.com/adsense/answer/9012903?hl=zh-Hant">Google 廣告技術供應商資訊</a>。個別供應商的實際使用情形取決於當時的廣告設定與您的同意選項。</p>

        <h2 class="h4 mt-4">Cookie 與同意選項</h2>
        <p>您可透過瀏覽器設定刪除或封鎖 Cookie；部分網站功能（例如記住文章密碼驗證狀態）可能因此無法使用。停用個人化廣告並不表示所有廣告都會消失。</p>
        <p>若網站向您顯示隱私權或同意選項，您可以依該視窗提供的方式管理選擇。Google 帳戶中的廣告偏好與瀏覽器 Cookie 設定需分別管理。</p>

        <h2 class="h4 mt-4">外部網站與聯絡</h2>
        <p>本站文章與社群連結可能導向其他網站；外部網站的資料處理方式依各自的隱私權政策辦理。</p>
        <p>若對本站的資料使用方式有疑問，或希望提出資料相關請求，請來信 <a href="mailto:summer.hung222@gmail.com">summer.hung222@gmail.com</a>。此信箱亦為本站公開的合作聯絡方式。</p>
        <p>本站會隨功能調整更新本政策，並在本頁標示更新日期。</p>
        <a href="{{ route('index') }}" class="btn btn-outline-dark mt-3">回到首頁</a>
    </article>
</main>
@endsection
