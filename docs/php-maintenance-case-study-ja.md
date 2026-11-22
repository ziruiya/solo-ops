# PHP 保守のケーススタディ

**個人学習用の架空の練習です。** 企業での業務・受託経験を示す事例ではありません。SoloOps から独立した PHP のサンプルで、外部データベースや追加ライブラリを使わずに実行できます。

## ケース 1 在庫数の 0 が未入力になる

### 問題コード

在庫照会の出力で、数量が未入力なら `missing`、入力済みなら文字列の数量を返します。入力元の在庫データは整数を渡し、CSV から取り込んだ値は文字列です。

```php
return empty($row['quantity']) ? 'missing' : (string) $row['quantity'];
```

### 再現

リポジトリのルートで起動済みの Compose を使います。前後に同じ六つの入力と期待値を渡します。 [起動手順](../README.md#09-quick-start)

```sh
docker compose exec -T app php examples/php-maintenance/case1/check.php before
docker compose exec -T app php examples/php-maintenance/case1/check.php after
```

旧版では数量 `0` と文字列 `'0'` がともに `missing` になります。未入力のセルも `missing` になるため、出力だけでは在庫なしと入力漏れを区別できません。

### 調査と診断の修正

| 段階 | 確認内容 |
| --- | --- |
| 最初の判断 | 出力時に整数を文字列へ変換すると `0` が空になると考えました。 |
| 反証 | プローブは `(string) 0 = 0` を表示します。変換前に `empty(0)` と `empty('0')` がともに `true` になり、キャスト前の分岐で `missing` が返されます。 |
| 修正した結論 | 欠損判定に真偽値への変換が使われ、入力済みのゼロまで欠損と判定されていました。 |

### 最小修正

欠けたキーは `null` として受け、`null` と空文字だけを未入力として処理します。数値を表示用文字列にする処理は維持します。

```php
$quantity = $row['quantity'] ?? null;

return $quantity === null || $quantity === '' ? 'missing' : (string) $quantity;
```

### 前後テストと回帰への影響

| テスト | 期待値 | 旧版 | 修正版 |
| --- | --- | --- | --- |
| 整数の `0` | `0` | `missing` | `0` |
| CSV の `'0'` | `0` | `missing` | `0` |
| 整数の `12` | `12` | `12` | `12` |
| `null`、空セル、キー欠落 | それぞれ `missing` | 期待値と一致 | 期待値と一致 |

末尾は `before: 4/6 passed`（終了コード 1）、`after: 6/6 passed`（終了コード 0）です。表示ラベルを生成する処理を変えるため、ゼロを未入力と数えていた集計は更新後に変わります。呼び出し側が渡す型は整数・数値文字列・`null`・未入力を想定しており、空白や非数値の入力の検証は取り込み側の責務です。 [旧版](../examples/php-maintenance/case1/before.php) · [修正版](../examples/php-maintenance/case1/after.php) · [テスト](../examples/php-maintenance/case1/check.php)

## ケース 2 UTC の受信時刻が東京の前日に集計される

### 問題コード

受信ログの時刻は UTC の ISO 8601 文字列で保存され、日次台帳は東京の日付で集計します。旧版は日付の部分を切り出していました。

```php
return substr($receivedAt, 0, 10);
```

### 再現

```sh
docker compose exec -T app php examples/php-maintenance/case2/check.php before
docker compose exec -T app php examples/php-maintenance/case2/check.php after
```

`2026-11-18T15:00:00Z` は東京の `2026-11-19 00:00:00` です。旧版では `2026-11-18` の台帳に入り、日付境界の前後で集計がずれます。

### 調査と診断の修正

| 段階 | 確認内容 |
| --- | --- |
| 最初の判断 | PHP のデフォルトタイムゾーンが UTC のため、日付がずれたと考えました。 |
| 反証 | プローブでデフォルトを `UTC` と `Asia/Tokyo` の両方に設定しても、旧版は同じ `2026-11-18` を返します。旧版の `substr` は時刻やタイムゾーンを解釈しません。 |
| 修正した結論 | UTC の文字列から東京の日付を求めるには、日付部分の抽出前に時刻を解析して東京へ変換する必要があります。 |

### 最小修正

集計用の日付を作る関数の中で、保存済みの時刻を明示的に東京へ変換します。DB 上の受信時刻と全体の PHP 設定はそのままです。

```php
return (new DateTimeImmutable($receivedAt))
    ->setTimezone(new DateTimeZone('Asia/Tokyo'))
    ->format('Y-m-d');
```

### 前後テストと回帰への影響

| 入力（UTC） | 東京での期待日 | 旧版 | 修正版 |
| --- | --- | --- | --- |
| `2026-11-18T03:00:00Z` | `2026-11-18` | `2026-11-18` | `2026-11-18` |
| `2026-11-18T14:59:59Z` | `2026-11-18` | `2026-11-18` | `2026-11-18` |
| `2026-11-18T15:00:00Z` | `2026-11-19` | `2026-11-18` | `2026-11-19` |
| `2026-12-31T15:00:00Z` | `2027-01-01` | `2026-12-31` | `2027-01-01` |

末尾は `before: 2/4 passed`（終了コード 1）、`after: 4/4 passed`（終了コード 0）です。同じ時刻の並び順と元ログは維持し、該当時間帯の台帳の日付と件数が変わります。既存の集計を再作成する場合は境界付近の振り分けを比較します。入力は受信時に検証済みの UTC 文字列を前提とします。 [旧版](../examples/php-maintenance/case2/before.php) · [修正版](../examples/php-maintenance/case2/after.php) · [テスト](../examples/php-maintenance/case2/check.php)
