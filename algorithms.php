<?php
//HashTable and Min-Heap are implemented here
class IngredientHashTable {
    private array $buckets = [];
    private int $size = 31;

    private function hash($key): int {
        $hash = 0;
        foreach (str_split((string)$key) as $char) {
            $hash = ($hash * 31 + ord($char)) % $this->size;
        }
        return $hash;
    }

    public function insert($key, $value): void {
        $index = $this->hash($key);
        foreach ($this->buckets[$index] ?? [] as $i => $pair) {
            if ((string)$pair[0] === (string)$key) {
                $this->buckets[$index][$i][1] = $value;
                return;
            }
        }
        $this->buckets[$index][] = [$key, $value];
    }

    public function get($key) {
        $index = $this->hash($key);
        foreach ($this->buckets[$index] ?? [] as $pair) {
            if ((string)$pair[0] === (string)$key) return $pair[1];
        }
        return null;
    }
}

class MinHeap {
    private array $heap = [];

    public function insert(array $item): void {
        $this->heap[] = $item; // item: ['priority'=>number, 'name'=>string]
        $i = count($this->heap) - 1;
        while ($i > 0) {
            $parent = intdiv($i - 1, 2);
            if ($this->heap[$parent]['priority'] <= $this->heap[$i]['priority']) break;
            [$this->heap[$parent], $this->heap[$i]] = [$this->heap[$i], $this->heap[$parent]];
            $i = $parent;
        }
    }

    public function removeMin(): ?array {
        if (!$this->heap) return null;
        $min = $this->heap[0];
        $last = array_pop($this->heap);
        if ($this->heap) {
            $this->heap[0] = $last;
            $i = 0;
            $n = count($this->heap);
            while (true) {
                $left = 2 * $i + 1;
                $right = 2 * $i + 2;
                $smallest = $i;
                if ($left < $n && $this->heap[$left]['priority'] < $this->heap[$smallest]['priority']) $smallest = $left;
                if ($right < $n && $this->heap[$right]['priority'] < $this->heap[$smallest]['priority']) $smallest = $right;
                if ($smallest === $i) break;
                [$this->heap[$i], $this->heap[$smallest]] = [$this->heap[$smallest], $this->heap[$i]];
                $i = $smallest;
            }
        }
        return $min;
    }

    public function isEmpty(): bool { return count($this->heap) === 0; }
}

// Linear search it returns every ingredient at or below its reorder level.
function linearReorderSearch(array $ingredients): array {
    $result = [];
    foreach ($ingredients as $item) {
        if ((float)$item['quantity'] <= (float)$item['reorder_level']) {
            $result[] = $item;
        }
    }
    return $result;
}

//PHP indexed arrays hold the retrieved transaction records, it called Dynamic Array