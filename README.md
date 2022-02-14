# PoW Faucet

This is a Riecoin PoW Faucet that shows how the PoW Credits system can be integrated to replace Captchas.

[StellaPool](https://github.com/Pttn/StellaPool) provides a Riecoin mining pool software that also distributes PoWc, and [Stelo.xyz](https://Stelo.xyz/Mining) is the first mining pool to use the PoWc system. You can mine there and earn your first PoWc.

To run the faucet, set up [Riecoin Core](https://riecoin.dev/en/Riecoin_Core), change the relevant lines in `index.php` to point to your Riecoin wallet, update the [SetCookie](https://www.php.net/manual/en/function.setcookie.php) arguments, make it accessible with Apache or with for example
```bash
php -S 127.0.0.1:8000
```
and you are done. You can also customize the amounts or the appearance, add your own features or constraints like a claim time limit per username, or just extract the PoWc code to integrate it in another of your services.

Also take a look at the [Riecoin Faucet](https://Riecoin.dev/Faucet) where you can consume your PoWc and see what we can do with this code!
