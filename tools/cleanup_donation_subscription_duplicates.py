import datetime
import json

import pymysql


def main() -> None:
    conn = pymysql.connect(
        host="localhost",
        port=3306,
        user="root",
        password="D3@mDraW_2026!#",
        database="drawdream_db",
        charset="utf8mb4",
        autocommit=False,
        cursorclass=pymysql.cursors.DictCursor,
    )
    cur = conn.cursor()

    cur.execute("DROP TEMPORARY TABLE IF EXISTS tmp_donation_merge_pairs")
    cur.execute(
        """
        CREATE TEMPORARY TABLE tmp_donation_merge_pairs AS
        SELECT
            s.donate_id AS subscription_row_id,
            p.donate_id AS payment_row_id
        FROM donation s
        JOIN donation p
          ON p.category_id = s.category_id
         AND p.target_id = s.target_id
         AND p.donor_id = s.donor_id
         AND p.transfer_datetime = s.transfer_datetime
        WHERE s.donate_type = 'child_subscription'
          AND COALESCE(s.payment_status, '') = 'subscription'
          AND COALESCE(s.amount, 0) = 0
          AND (s.omise_charge_id IS NULL OR s.omise_charge_id = '')
          AND COALESCE(p.payment_status, '') = 'completed'
          AND COALESCE(p.amount, 0) > 0
          AND COALESCE(p.donate_type, '') = ''
          AND p.omise_charge_id IS NOT NULL
        """
    )

    cur.execute("SELECT COUNT(*) AS c FROM tmp_donation_merge_pairs")
    pair_count = int(cur.fetchone()["c"])
    result = {"pair_count": pair_count}

    if pair_count > 0:
        ts = datetime.datetime.now().strftime("%Y%m%d_%H%M%S")
        backup_table = f"donation_cleanup_backup_{ts}"
        cur.execute(
            f"""
            CREATE TABLE {backup_table} AS
            SELECT DISTINCT d.*
            FROM donation d
            JOIN tmp_donation_merge_pairs m
              ON d.donate_id = m.subscription_row_id
              OR d.donate_id = m.payment_row_id
            """
        )

        cur.execute(
            """
            UPDATE donation s
            JOIN tmp_donation_merge_pairs m ON m.subscription_row_id = s.donate_id
            JOIN donation p ON p.donate_id = m.payment_row_id
            SET
                s.amount = p.amount,
                s.service_fee = p.service_fee,
                s.payment_status = 'completed',
                s.transfer_datetime = p.transfer_datetime,
                s.omise_charge_id = p.omise_charge_id,
                s.transaction_status = 'completed',
                s.tax_id = CASE
                    WHEN COALESCE(s.tax_id, '') = '' THEN p.tax_id
                    ELSE s.tax_id
                END
            """
        )
        updated = int(cur.rowcount)

        cur.execute(
            """
            DELETE d
            FROM donation d
            JOIN tmp_donation_merge_pairs m ON m.payment_row_id = d.donate_id
            """
        )
        deleted = int(cur.rowcount)
        conn.commit()
        result.update(
            {
                "backup_table": backup_table,
                "updated_subscription_rows": updated,
                "deleted_payment_rows": deleted,
            }
        )
    else:
        conn.rollback()
        result.update(
            {
                "backup_table": None,
                "updated_subscription_rows": 0,
                "deleted_payment_rows": 0,
            }
        )

    cur.execute(
        """
        SELECT
            donate_id,
            category_id,
            target_id,
            donor_id,
            amount,
            payment_status,
            transfer_datetime,
            omise_charge_id,
            donate_type,
            recurring_status,
            recurring_plan_code
        FROM donation
        WHERE donate_type = 'child_subscription'
        ORDER BY donate_id
        """
    )
    result["remaining_subscription_rows"] = cur.fetchall()

    print(json.dumps(result, ensure_ascii=False, default=str, indent=2))
    cur.close()
    conn.close()


if __name__ == "__main__":
    main()
